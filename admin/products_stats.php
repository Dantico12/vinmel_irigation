<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 60);

// Include security functions
require_once 'security.php';

// Initialize secure session
initSecureSession();

// Set security headers
setSecurityHeaders();

// Include other required files
require_once 'config.php';
require_once 'functions.php';

// Add performance monitoring
$start_time = microtime(true);
$query_count = 0;

// CSRF Token
$csrf_token = generateCSRFToken();

/* ----------------------------
    PERFORMANCE FUNCTIONS
-----------------------------*/

// Query logging function
function logQuery($sql) {
    global $query_count;
    $query_count++;
    error_log("Query #$query_count: " . substr($sql, 0, 200));
}

// Safe date display function
function safeDateDisplay($dateString, $format = 'F j, Y') {
    if (empty($dateString) || $dateString === '0000-00-00' || $dateString === '1970-01-01' || trim($dateString) === '') {
        return 'Not set';
    }
    $timestamp = strtotime($dateString);
    return $timestamp ? date($format, $timestamp) : 'Invalid date';
}

// Safe number of days calculation
function calculateDaysBetween($startDate, $endDate) {
    if (empty($startDate) || empty($endDate) || 
        $startDate === '0000-00-00' || $endDate === '0000-00-00' ||
        $startDate === '1970-01-01' || $endDate === '1970-01-01') {
        return 'Duration not set';
    }
    
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    
    if (!$start || !$end) {
        return 'Invalid dates';
    }
    
    $days = floor(($end - $start) / (60 * 60 * 24) + 1);
    return number_format($days) . ' days';
}

/* ----------------------------
    SECURITY FUNCTIONS
-----------------------------*/



// Rate limiting check for report generation
if (!checkRateLimit('period_summary_report', 10, 60)) {
    securityLog("Rate limit exceeded for period summary report", 'WARNING', $_SESSION['user_id']);
    die("Too many requests. Please try again later.");
}

// Database connection with error handling
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Optimize database settings for large datasets
    $db->query("SET SESSION sql_mode=''");
    $db->query("SET NAMES utf8mb4");
    $db->query("SET SESSION group_concat_max_len = 1000000");
    
    // Test connection
    if (!$db->ping()) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    securityLog("Database connection failed: " . $e->getMessage(), 'ERROR');
    die("Database connection failed. Please try again later.");
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'unknown';
$message = '';
$error = '';

// ===== GET FILTER PARAMETERS WITH SANITIZATION =====
$selected_period_id = isset($_GET['period_id']) ? sanitizeInput($_GET['period_id'], 'int') : null;
$year_filter = isset($_GET['year']) ? sanitizeInput($_GET['year'], 'int') : date('Y');
$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
$export_format = isset($_GET['export']) ? sanitizeInput($_GET['export']) : '';

// Validate year filter
if (!validateInt($year_filter, 2000, 2100)) {
    $year_filter = date('Y');
}

// Validate period ID
if ($selected_period_id && !validateInt($selected_period_id, 1)) {
    $selected_period_id = null;
}

// Pagination parameters with validation
$page = isset($_GET['page']) ? max(1, sanitizeInput($_GET['page'], 'int')) : 1;
$per_page = 50; // Reduced from displaying all products
$offset = ($page - 1) * $per_page;

// Search and filter parameters with sanitization
$search = isset($_GET['search']) ? sanitizeInput(trim($_GET['search'])) : '';
$category_filter = isset($_GET['category']) ? sanitizeInput($_GET['category'], 'int') : 0;
$stock_filter = isset($_GET['stock']) ? sanitizeInput($_GET['stock']) : 'all';

// Validate stock filter
$allowed_stock_filters = ['all', 'healthy', 'low', 'out'];
if (!in_array($stock_filter, $allowed_stock_filters)) {
    $stock_filter = 'all';
}

// ===== EXPORT FUNCTIONALITY (OPTIMIZED) =====
if ($export_format && $selected_period_id) {
    // Validate CSRF token for export actions
    if (!isset($_GET['csrf_token']) || !validateCSRFToken($_GET['csrf_token'])) {
        securityLog("Invalid CSRF token for export", 'WARNING', $user_id);
        die("Security error: Invalid token");
    }
    
    // Check rate limit for exports
    if (!checkRateLimit('export_period_summary', 5, 300)) {
        securityLog("Export rate limit exceeded", 'WARNING', $user_id);
        die("Too many export requests. Please try again later.");
    }
    
    // Handle export requests with streaming
    header("Cache-Control: public");
    header("Content-Description: File Transfer");
    
    if ($export_format === 'csv') {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=period_summary_" . date('Y-m-d') . ".csv");
    } elseif ($export_format === 'excel') {
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=period_summary_" . date('Y-m-d') . ".xls");
    } else {
        $export_format = 'csv';
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=period_summary_" . date('Y-m-d') . ".csv");
    }
    
    // Get period info
    $period_sql = "SELECT * FROM time_periods WHERE id = ?";
    logQuery($period_sql);
    $period_stmt = prepareStatement($db, $period_sql, [$selected_period_id], "i");
    
    if (!$period_stmt) {
        securityLog("Failed to prepare period statement for export", 'ERROR', $user_id);
        die("Database error occurred");
    }
    
    $period_stmt->execute();
    $period_info = $period_stmt->get_result()->fetch_assoc();
    
    if ($period_info && isset($period_info['start_date']) && isset($period_info['end_date'])) {
        // OPTIMIZED: Get aggregated data first
        $aggregate_sql = "SELECT 
            p.id,
            SUM(CASE WHEN st.transaction_type = 'stock_in' AND DATE(st.created_at) BETWEEN ? AND ? THEN st.quantity ELSE 0 END) as stock_added,
            SUM(CASE WHEN st.transaction_type = 'stock_out' AND DATE(st.created_at) BETWEEN ? AND ? THEN st.quantity ELSE 0 END) as stock_removed
        FROM products p
        LEFT JOIN stock_transactions st ON p.id = st.product_id 
            AND DATE(st.created_at) BETWEEN ? AND ?
        WHERE p.period_id = ?
        GROUP BY p.id";
        
        logQuery($aggregate_sql);
        $aggregate_stmt = prepareStatement($db, $aggregate_sql, [
            $period_info['start_date'], $period_info['end_date'],
            $period_info['start_date'], $period_info['end_date'],
            $period_info['start_date'], $period_info['end_date'],
            $selected_period_id
        ], "ssssssi");
        
        if (!$aggregate_stmt) {
            securityLog("Failed to prepare aggregate statement for export", 'ERROR', $user_id);
            die("Database error occurred");
        }
        
        $aggregate_stmt->execute();
        $aggregate_result = $aggregate_stmt->get_result();
        $aggregated_data = [];
        while ($row = $aggregate_result->fetch_assoc()) {
            $aggregated_data[$row['id']] = $row;
        }
        
        // Get sales aggregated data
        $sales_sql = "SELECT 
            ti.product_id,
            SUM(ti.quantity) as sold_quantity
        FROM transaction_items ti
        JOIN transactions t ON ti.transaction_id = t.id
        WHERE t.time_period_id = ?
        GROUP BY ti.product_id";
        
        logQuery($sales_sql);
        $sales_stmt = prepareStatement($db, $sales_sql, [$selected_period_id], "i");
        
        if (!$sales_stmt) {
            securityLog("Failed to prepare sales statement for export", 'ERROR', $user_id);
            die("Database error occurred");
        }
        
        $sales_stmt->execute();
        $sales_result = $sales_stmt->get_result();
        $sales_data = [];
        while ($row = $sales_result->fetch_assoc()) {
            $sales_data[$row['product_id']] = $row['sold_quantity'];
        }
        
        // Now get products with pagination for memory efficiency
        $product_sql = "SELECT 
            p.id,
            p.name,
            p.sku,
            p.stock_quantity as current_stock,
            p.min_stock,
            p.cost_price,
            p.selling_price,
            c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.period_id = ?
        AND p.is_active = 1
        ORDER BY p.name
        LIMIT 10000"; // Max 10,000 for export
        
        logQuery($product_sql);
        $product_stmt = prepareStatement($db, $product_sql, [$selected_period_id], "i");
        
        if (!$product_stmt) {
            securityLog("Failed to prepare product statement for export", 'ERROR', $user_id);
            die("Database error occurred");
        }
        
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();
        
        // Output headers for CSV
        $output = fopen('php://output', 'w');
        
        // CSV header
        fputcsv($output, [
            'Product ID', 'Product Name', 'SKU', 'Category', 
            'Cost Price', 'Selling Price', 'Starting Stock',
            'Stock Added', 'Stock Removed', 'Sold', 
            'Current Stock', 'Min Stock', 'Status'
        ]);
        
        // Stream data row by row to avoid memory issues
        $row_count = 0;
        while ($product = $product_result->fetch_assoc()) {
            $product_id = $product['id'];
            $stock_added = $aggregated_data[$product_id]['stock_added'] ?? 0;
            $stock_removed = $aggregated_data[$product_id]['stock_removed'] ?? 0;
            $sold = $sales_data[$product_id] ?? 0;
            
            $starting_stock = ($product['current_stock'] + $stock_removed) - $stock_added + $sold;
            
            $status = 'In Stock';
            if ($product['current_stock'] == 0) {
                $status = 'Out of Stock';
            } elseif ($product['current_stock'] <= $product['min_stock']) {
                $status = 'Low Stock';
            }
            
            fputcsv($output, [
                $product['id'],
                sanitizeInput($product['name']),
                sanitizeInput($product['sku'] ?? ''),
                sanitizeInput($product['category_name'] ?? 'Uncategorized'),
                number_format($product['cost_price'], 2),
                number_format($product['selling_price'], 2),
                $starting_stock,
                $stock_added,
                $stock_removed,
                $sold,
                $product['current_stock'],
                $product['min_stock'],
                $status
            ]);
            
            $row_count++;
            
            // Flush output periodically
            if ($row_count % 100 == 0) {
                flush();
            }
        }
        
        fclose($output);
        securityLog("Exported period summary for period ID: $selected_period_id with $row_count rows", 'INFO', $user_id);
        exit();
    }
    
    // If no period info, redirect back
    header("Location: period_summary.php");
    exit();
}

// ===== FETCH YEARS FOR FILTER =====
$years_sql = "SELECT DISTINCT year FROM time_periods ORDER BY year DESC";
logQuery($years_sql);
$years_result = $db->query($years_sql);
$years = $years_result ? $years_result->fetch_all(MYSQLI_ASSOC) : [];

// ===== FETCH CATEGORIES FOR FILTER =====
$categories_sql = "SELECT id, name FROM categories ORDER BY name";
logQuery($categories_sql);
$categories_result = $db->query($categories_sql);
$categories = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];

// ===== FETCH PERIODS FOR SELECTED YEAR =====
$periods = [];
if ($year_filter) {
    $periods_sql = "SELECT id, period_name, year, month, start_date, end_date, is_locked 
                   FROM time_periods 
                   WHERE year = ? 
                   ORDER BY year DESC, month DESC";
    logQuery($periods_sql);
    $periods_stmt = prepareStatement($db, $periods_sql, [$year_filter], "i");
    
    if ($periods_stmt) {
        $periods_stmt->execute();
        $periods_result = $periods_stmt->get_result();
        $periods = $periods_result ? $periods_result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// ===== IF PERIOD SELECTED, GET OPTIMIZED STATISTICS =====
$period_info = null;
$summary_data = null;
$product_details = [];
$monthly_data = [];
$total_products = 0;
$total_pages = 0;

if ($selected_period_id) {
    // 1. Get period basic info
    $period_sql = "SELECT * FROM time_periods WHERE id = ?";
    logQuery($period_sql);
    $period_stmt = prepareStatement($db, $period_sql, [$selected_period_id], "i");
    
    if (!$period_stmt) {
        $error = "Database error occurred while fetching period information.";
        securityLog("Failed to prepare period statement: " . $db->error, 'ERROR', $user_id);
    } else {
        $period_stmt->execute();
        $period_result = $period_stmt->get_result();
        $period_info = $period_result ? $period_result->fetch_assoc() : null;
        
        if ($period_info && is_array($period_info)) {
            // Check if start_date and end_date exist
            $has_dates = isset($period_info['start_date']) && isset($period_info['end_date']) && 
                         !empty($period_info['start_date']) && !empty($period_info['end_date']) &&
                         $period_info['start_date'] !== '0000-00-00' && $period_info['end_date'] !== '0000-00-00';
            
            // Initialize summary data array
            $summary_data = [
                'products_added' => ['count' => 0, 'initial_stock' => 0],
                'updates' => ['products_updated' => 0, 'stock_added' => 0, 'stock_removed' => 0, 'total_updates' => 0],
                'sales' => ['products_sold' => 0, 'total_quantity_sold' => 0, 'total_sales_value' => 0, 'total_transactions' => 0],
                'current_stock' => ['active_products' => 0, 'current_stock' => 0, 'stock_value' => 0, 'low_stock_items' => 0, 'out_of_stock_items' => 0],
                'transaction_breakdown' => [],
                'category_sales' => [],
                'top_products' => [],
                'monthly_data' => []
            ];
            
            // 2. OPTIMIZED: Get all summary statistics in single queries
            
            // Products added and current stock in one query
            $products_summary_sql = "SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN is_carried_forward = 0 THEN 1 END) as new_products,
                SUM(CASE WHEN is_carried_forward = 0 THEN stock_quantity ELSE 0 END) as new_stock,
                SUM(stock_quantity) as current_stock,
                SUM(stock_quantity * cost_price) as stock_value,
                COUNT(CASE WHEN stock_quantity <= min_stock THEN 1 END) as low_stock_items,
                COUNT(CASE WHEN stock_quantity = 0 THEN 1 END) as out_of_stock_items
            FROM products 
            WHERE period_id = ? 
            AND is_active = 1";
            
            logQuery($products_summary_sql);
            $products_summary_stmt = prepareStatement($db, $products_summary_sql, [$selected_period_id], "i");
            
            if ($products_summary_stmt) {
                $products_summary_stmt->execute();
                $products_summary_result = $products_summary_stmt->get_result();
                if ($products_summary_result) {
                    $products_summary = $products_summary_result->fetch_assoc();
                    if ($products_summary) {
                        $summary_data['products_added'] = [
                            'count' => $products_summary['new_products'] ?? 0,
                            'initial_stock' => $products_summary['new_stock'] ?? 0
                        ];
                        $summary_data['current_stock'] = [
                            'active_products' => $products_summary['total_products'] ?? 0,
                            'current_stock' => $products_summary['current_stock'] ?? 0,
                            'stock_value' => $products_summary['stock_value'] ?? 0,
                            'low_stock_items' => $products_summary['low_stock_items'] ?? 0,
                            'out_of_stock_items' => $products_summary['out_of_stock_items'] ?? 0
                        ];
                    }
                }
            }
            
            // 3. OPTIMIZED: Stock updates with single query (only if dates exist)
            if ($has_dates) {
                $updates_sql = "SELECT 
                    transaction_type,
                    COUNT(DISTINCT st.product_id) as products_updated,
                    SUM(st.quantity) as total_quantity,
                    COUNT(st.id) as total_updates
                FROM stock_transactions st
                JOIN products p ON st.product_id = p.id
                WHERE p.period_id = ?
                AND DATE(st.created_at) BETWEEN ? AND ?
                GROUP BY transaction_type WITH ROLLUP";
                
                logQuery($updates_sql);
                $updates_stmt = prepareStatement($db, $updates_sql, [
                    $selected_period_id, 
                    $period_info['start_date'], 
                    $period_info['end_date']
                ], "iss");
                
                if ($updates_stmt) {
                    $updates_stmt->execute();
                    $updates_result = $updates_stmt->get_result();
                    if ($updates_result) {
                        $stock_added = 0;
                        $stock_removed = 0;
                        $products_updated = 0;
                        $total_updates = 0;
                        
                        while ($row = $updates_result->fetch_assoc()) {
                            if ($row['transaction_type'] === 'stock_in') {
                                $stock_added = $row['total_quantity'] ?? 0;
                            } elseif ($row['transaction_type'] === 'stock_out') {
                                $stock_removed = $row['total_quantity'] ?? 0;
                            } elseif ($row['transaction_type'] === null) { // ROLLUP row
                                $products_updated = $row['products_updated'] ?? 0;
                                $total_updates = $row['total_updates'] ?? 0;
                            }
                            
                            if ($row['transaction_type'] !== null) {
                                $summary_data['transaction_breakdown'][] = [
                                    'transaction_type' => sanitizeInput($row['transaction_type']),
                                    'transaction_count' => $row['total_updates'] ?? 0,
                                    'total_quantity' => $row['total_quantity'] ?? 0
                                ];
                            }
                        }
                        
                        $summary_data['updates'] = [
                            'products_updated' => $products_updated,
                            'stock_added' => $stock_added,
                            'stock_removed' => $stock_removed,
                            'total_updates' => $total_updates
                        ];
                    }
                }
            }
            
            // 4. Sales data
            $sales_sql = "SELECT 
                COUNT(DISTINCT ti.product_id) as products_sold,
                SUM(ti.quantity) as total_quantity_sold,
                SUM(ti.total_price) as total_sales_value,
                COUNT(DISTINCT t.id) as total_transactions
            FROM transactions t
            JOIN transaction_items ti ON t.id = ti.transaction_id
            WHERE t.time_period_id = ?";
            
            logQuery($sales_sql);
            $sales_stmt = prepareStatement($db, $sales_sql, [$selected_period_id], "i");
            
            if ($sales_stmt) {
                $sales_stmt->execute();
                $sales_result = $sales_stmt->get_result();
                if ($sales_result) {
                    $sales_data = $sales_result->fetch_assoc();
                    if ($sales_data) {
                        $summary_data['sales'] = [
                            'products_sold' => $sales_data['products_sold'] ?? 0,
                            'total_quantity_sold' => $sales_data['total_quantity_sold'] ?? 0,
                            'total_sales_value' => $sales_data['total_sales_value'] ?? 0,
                            'total_transactions' => $sales_data['total_transactions'] ?? 0
                        ];
                    }
                }
            }
            
            // 5. OPTIMIZED: Get aggregated product data in batches
            
            // First, get total count for pagination
            $count_sql = "SELECT COUNT(*) as total 
                         FROM products p 
                         WHERE p.period_id = ? 
                         AND p.is_active = 1";
            
            // Add search filter
            if ($search) {
                $count_sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
            }
            
            // Add category filter
            if ($category_filter) {
                $count_sql .= " AND p.category_id = ?";
            }
            
            // Add stock filter
            if ($stock_filter === 'low') {
                $count_sql .= " AND p.stock_quantity <= p.min_stock AND p.stock_quantity > 0";
            } elseif ($stock_filter === 'out') {
                $count_sql .= " AND p.stock_quantity = 0";
            } elseif ($stock_filter === 'healthy') {
                $count_sql .= " AND p.stock_quantity > p.min_stock";
            }
            
            logQuery($count_sql);
            $count_stmt = $db->prepare($count_sql);
            
            if ($count_stmt) {
                if ($search) {
                    $search_term = "%{$search}%";
                    if ($category_filter) {
                        $count_stmt->bind_param("issi", $selected_period_id, $search_term, $search_term, $category_filter);
                    } else {
                        $count_stmt->bind_param("iss", $selected_period_id, $search_term, $search_term);
                    }
                } elseif ($category_filter) {
                    $count_stmt->bind_param("ii", $selected_period_id, $category_filter);
                } else {
                    $count_stmt->bind_param("i", $selected_period_id);
                }
                
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                $total_row = $count_result->fetch_assoc();
                $total_products = $total_row['total'] ?? 0;
                $total_pages = ceil($total_products / $per_page);
            }
            
            // OPTIMIZED: Get aggregated stock transaction data in bulk
            $aggregate_data = [];
            if ($has_dates) {
                $aggregate_sql = "SELECT 
                    p.id as product_id,
                    SUM(CASE WHEN st.transaction_type = 'stock_in' THEN st.quantity ELSE 0 END) as stock_added,
                    SUM(CASE WHEN st.transaction_type = 'stock_out' THEN st.quantity ELSE 0 END) as stock_removed
                FROM products p
                LEFT JOIN stock_transactions st ON p.id = st.product_id 
                    AND DATE(st.created_at) BETWEEN ? AND ?
                WHERE p.period_id = ?
                GROUP BY p.id";
                
                logQuery($aggregate_sql);
                $aggregate_stmt = prepareStatement($db, $aggregate_sql, [
                    $period_info['start_date'], 
                    $period_info['end_date'], 
                    $selected_period_id
                ], "ssi");
                
                if ($aggregate_stmt) {
                    $aggregate_stmt->execute();
                    $aggregate_result = $aggregate_stmt->get_result();
                    while ($row = $aggregate_result->fetch_assoc()) {
                        $aggregate_data[$row['product_id']] = $row;
                    }
                }
            }
            
            // OPTIMIZED: Get sales data in bulk
            $sales_aggregate_sql = "SELECT 
                ti.product_id,
                SUM(ti.quantity) as sold_quantity
            FROM transaction_items ti
            JOIN transactions t ON ti.transaction_id = t.id
            WHERE t.time_period_id = ?
            GROUP BY ti.product_id";
            
            logQuery($sales_aggregate_sql);
            $sales_aggregate_stmt = prepareStatement($db, $sales_aggregate_sql, [$selected_period_id], "i");
            
            if ($sales_aggregate_stmt) {
                $sales_aggregate_stmt->execute();
                $sales_aggregate_result = $sales_aggregate_stmt->get_result();
                $sales_aggregate_data = [];
                while ($row = $sales_aggregate_result->fetch_assoc()) {
                    $sales_aggregate_data[$row['product_id']] = $row['sold_quantity'];
                }
            }
            
            // 6. Get paginated product details with aggregated data
            $product_details_sql = "SELECT 
                p.id,
                p.name,
                p.sku,
                p.stock_quantity as current_stock,
                p.min_stock,
                p.cost_price,
                p.selling_price,
                c.name as category_name,
                c.id as category_id
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.period_id = ?
            AND p.is_active = 1";
            
            // Add search filter
            if ($search) {
                $product_details_sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
            }
            
            // Add category filter
            if ($category_filter) {
                $product_details_sql .= " AND p.category_id = ?";
            }
            
            // Add stock filter
            if ($stock_filter === 'low') {
                $product_details_sql .= " AND p.stock_quantity <= p.min_stock AND p.stock_quantity > 0";
            } elseif ($stock_filter === 'out') {
                $product_details_sql .= " AND p.stock_quantity = 0";
            } elseif ($stock_filter === 'healthy') {
                $product_details_sql .= " AND p.stock_quantity > p.min_stock";
            }
            
            $product_details_sql .= " ORDER BY p.name LIMIT ? OFFSET ?";
            
            logQuery($product_details_sql);
            $product_details_stmt = $db->prepare($product_details_sql);
            
            if ($product_details_stmt) {
                if ($search) {
                    $search_term = "%{$search}%";
                    if ($category_filter) {
                        $product_details_stmt->bind_param("issiii", 
                            $selected_period_id, $search_term, $search_term, $category_filter, $per_page, $offset);
                    } else {
                        $product_details_stmt->bind_param("issii", 
                            $selected_period_id, $search_term, $search_term, $per_page, $offset);
                    }
                } elseif ($category_filter) {
                    $product_details_stmt->bind_param("iiii", 
                        $selected_period_id, $category_filter, $per_page, $offset);
                } else {
                    $product_details_stmt->bind_param("iii", 
                        $selected_period_id, $per_page, $offset);
                }
                
                $product_details_stmt->execute();
                $product_details_result = $product_details_stmt->get_result();
                if ($product_details_result) {
                    $product_details = $product_details_result->fetch_all(MYSQLI_ASSOC);
                    
                    // Enhance with aggregated data (in PHP, no extra queries)
                    foreach ($product_details as &$product) {
                        $product_id = $product['id'];
                        $product['stock_added_during_period'] = $aggregate_data[$product_id]['stock_added'] ?? 0;
                        $product['stock_removed_during_period'] = $aggregate_data[$product_id]['stock_removed'] ?? 0;
                        $product['sold_this_period'] = $sales_aggregate_data[$product_id] ?? 0;
                    }
                    unset($product); // Break reference
                }
            }
            
            // 7. Top selling products (cached query)
            $top_products_sql = "SELECT 
                p.name as product_name,
                p.sku,
                SUM(ti.quantity) as total_sold,
                SUM(ti.total_price) as total_value
            FROM transaction_items ti
            JOIN transactions t ON ti.transaction_id = t.id
            JOIN products p ON ti.product_id = p.id
            WHERE t.time_period_id = ?
            GROUP BY p.id, p.name, p.sku
            ORDER BY total_sold DESC
            LIMIT 10";
            
            logQuery($top_products_sql);
            $top_products_stmt = prepareStatement($db, $top_products_sql, [$selected_period_id], "i");
            
            if ($top_products_stmt) {
                $top_products_stmt->execute();
                $top_products_result = $top_products_stmt->get_result();
                if ($top_products_result) {
                    $summary_data['top_products'] = $top_products_result->fetch_all(MYSQLI_ASSOC);
                }
            }
            
            // 8. Category sales (cached)
            $category_sales_sql = "SELECT 
                c.name as category_name,
                COUNT(DISTINCT ti.product_id) as products_sold,
                SUM(ti.quantity) as total_quantity,
                SUM(ti.total_price) as total_value
            FROM transaction_items ti
            JOIN transactions t ON ti.transaction_id = t.id
            JOIN products p ON ti.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE t.time_period_id = ?
            GROUP BY c.name
            ORDER BY total_value DESC
            LIMIT 5";
            
            logQuery($category_sales_sql);
            $category_sales_stmt = prepareStatement($db, $category_sales_sql, [$selected_period_id], "i");
            
            if ($category_sales_stmt) {
                $category_sales_stmt->execute();
                $category_sales_result = $category_sales_stmt->get_result();
                if ($category_sales_result) {
                    $summary_data['category_sales'] = $category_sales_result->fetch_all(MYSQLI_ASSOC);
                }
            }
            
            // 9. Monthly data
            $monthly_sql = "SELECT 
                tp.month,
                tp.period_name,
                COALESCE(SUM(t.net_amount), 0) as total_sales,
                COUNT(DISTINCT t.id) as transaction_count,
                COUNT(DISTINCT ti.product_id) as products_sold,
                SUM(ti.quantity) as total_quantity_sold
            FROM time_periods tp
            LEFT JOIN transactions t ON tp.id = t.time_period_id
            LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
            WHERE tp.year = ?
            GROUP BY tp.month, tp.period_name
            ORDER BY tp.month";
            
            logQuery($monthly_sql);
            $monthly_stmt = prepareStatement($db, $monthly_sql, [$year_filter], "i");
            
            if ($monthly_stmt) {
                $monthly_stmt->execute();
                $monthly_result = $monthly_stmt->get_result();
                if ($monthly_result) {
                    $monthly_data = $monthly_result->fetch_all(MYSQLI_ASSOC);
                    $summary_data['monthly_data'] = $monthly_data;
                }
            }
            
            // Calculate performance metrics
            $end_time = microtime(true);
            $execution_time = round($end_time - $start_time, 3);
            securityLog("Period Summary loaded in {$execution_time}s with {$query_count} queries for {$total_products} products", 'INFO', $user_id);
        }
    }
}

// Add cache headers for better performance
header("Cache-Control: private, max-age=300, stale-while-revalidate=60");

// ===== HTML OUTPUT STARTS HERE =====
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Period Summary Report - Vinmel Irrigation Business System">
    <meta name="robots" content="noindex, nofollow">
    <title>Period Summary Report - Vinmel Irrigation</title>
    
    <!-- Bootstrap 5 with integrity hashes -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" 
          integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" 
          crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" 
          integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" 
          crossorigin="anonymous">
    <link href="style.css" rel="stylesheet">
    
    <!-- DataTables for better table performance -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header mb-4">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-chart-bar me-2"></i>Period Summary Report
                        </h1>
                        <p class="lead mb-0">
                            Track product additions, updates, sales, and inventory per period
                            <?php if ($selected_period_id && $period_info): ?>
                                - <strong><?= htmlspecialchars($period_info['period_name'] ?? 'Unnamed Period') ?></strong>
                            <?php endif; ?>
                        </p>
                        <?php if ($selected_period_id): ?>
                            <div class="text-muted small">
                                Showing <?= number_format($total_products) ?> total products • 
                                Page <?= $page ?> of <?= $total_pages ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <?php if ($selected_period_id && $period_info): ?>
                            <div class="badge bg-primary fs-6 p-2">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?= htmlspecialchars($period_info['period_name'] ?? 'Unnamed Period') ?>
                            </div>
                        <?php endif; ?>
                        <div class="btn-group ms-2">
                            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <?php if ($selected_period_id): ?>
                                <a href="?period_id=<?= $selected_period_id ?>&export=csv&csrf_token=<?= $csrf_token ?>" class="btn btn-success">
                                    <i class="fas fa-file-csv me-2"></i>Export CSV
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Loading Overlay -->
                <div class="loading-overlay" id="loadingOverlay">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-3">Loading report data...</div>
                </div>

                <!-- Filter Controls -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row align-items-end" id="filterForm">
                            <input type="hidden" name="page" value="1">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-bold">Select Year</label>
                                <select name="year" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $year): ?>
                                        <option value="<?= htmlspecialchars($year['year'] ?? '') ?>" 
                                            <?= $year_filter == ($year['year'] ?? '') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($year['year'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-bold">Select Period</label>
                                <select name="period_id" class="form-select" onchange="this.form.submit()">
                                    <option value="">-- Select a Period --</option>
                                    <?php foreach ($periods as $period): ?>
                                        <?php 
                                            $start_date_display = isset($period['start_date']) && !empty($period['start_date']) ? 
                                                date('M j', strtotime($period['start_date'])) : 'Start N/A';
                                            $end_date_display = isset($period['end_date']) && !empty($period['end_date']) ? 
                                                date('M j, Y', strtotime($period['end_date'])) : 'End N/A';
                                        ?>
                                        <option value="<?= htmlspecialchars($period['id'] ?? '') ?>" 
                                            <?= $selected_period_id == ($period['id'] ?? '') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($period['period_name'] ?? 'Unnamed Period') ?>
                                            <?= ($period['is_locked'] ?? 0) ? ' 🔒' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <label class="form-label fw-bold">Category</label>
                                <select name="category" class="form-select" onchange="this.form.submit()">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category['id'] ?? '') ?>" 
                                            <?= $category_filter == ($category['id'] ?? '') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['name'] ?? 'Uncategorized') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <label class="form-label fw-bold">Stock Status</label>
                                <select name="stock" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?= $stock_filter === 'all' ? 'selected' : '' ?>>All Stock</option>
                                    <option value="healthy" <?= $stock_filter === 'healthy' ? 'selected' : '' ?>>Healthy Stock</option>
                                    <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                                    <option value="out" <?= $stock_filter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-2"></i>Apply
                                </button>
                            </div>
                            
                            <?php if ($selected_period_id): ?>
                            <div class="col-md-12">
                                <div class="input-group mb-3">
                                    <input type="text" 
                                           name="search" 
                                           class="form-control" 
                                           placeholder="Search products by name or SKU..." 
                                           value="<?= htmlspecialchars($search) ?>"
                                           onkeyup="if(event.keyCode === 13) this.form.submit()">
                                    <button class="btn btn-outline-secondary" type="submit">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <?php if ($search): ?>
                                        <a href="?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>" 
                                           class="btn btn-outline-danger">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </form>
                        
                        <?php if ($selected_period_id && $period_info && is_array($period_info)): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="period-info-banner">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h4 class="mb-2">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <?= htmlspecialchars($period_info['period_name'] ?? 'Unnamed Period') ?>
                                            </h4>
                                            <p class="mb-0">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?= safeDateDisplay($period_info['start_date'] ?? '') ?> 
                                                to 
                                                <?= safeDateDisplay($period_info['end_date'] ?? '') ?>
                                            </p>
                                            <p class="mb-0">
                                                <i class="fas fa-clock me-1"></i>
                                                <?= calculateDaysBetween($period_info['start_date'] ?? '', $period_info['end_date'] ?? '') ?>
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-light text-dark fs-6 p-2">
                                                Status: 
                                                <?php if ($period_info['is_locked'] ?? 0): ?>
                                                    <span class="text-danger">Locked 🔒</span>
                                                <?php elseif ($period_info['is_active'] ?? 0): ?>
                                                    <span class="text-success">Active</span>
                                                <?php else: ?>
                                                    <span class="text-warning">Inactive</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Performance Stats (hidden in production) -->
                <?php if (false): // Set to true for debugging ?>
                <div class="alert alert-info">
                    <i class="fas fa-tachometer-alt me-2"></i>
                    Performance: Loaded in <?= $execution_time ?? 'N/A' ?>s with <?= $query_count ?> queries | 
                    Memory: <?= number_format(memory_get_peak_usage() / 1024 / 1024, 2) ?>MB | 
                    Products: <?= number_format($total_products) ?>
                </div>
                <?php endif; ?>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$selected_period_id): ?>
                    <!-- Select Period Prompt -->
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-chart-line fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted">Select a Period to View Report</h4>
                            <p class="text-muted mb-4">Choose a year and period from the filters above to see detailed product analytics</p>
                            <div class="d-flex justify-content-center gap-3">
                                <i class="fas fa-arrow-up text-primary fa-2x"></i>
                                <p class="text-muted">Use the filter controls to get started</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Summary Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card summary-card border-top border-primary">
                                <div class="card-body text-center">
                                    <div class="summary-icon bg-primary bg-opacity-10 text-primary mx-auto">
                                        <i class="fas fa-plus-circle"></i>
                                    </div>
                                    <div class="summary-value text-primary">
                                        <?= number_format($summary_data['products_added']['count'] ?? 0) ?>
                                    </div>
                                    <div class="summary-label">Products Added</div>
                                    <p class="small text-muted mt-2 mb-0">
                                        <?= number_format($summary_data['products_added']['initial_stock'] ?? 0) ?> initial stock
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card summary-card border-top border-info">
                                <div class="card-body text-center">
                                    <div class="summary-icon bg-info bg-opacity-10 text-info mx-auto">
                                        <i class="fas fa-sync-alt"></i>
                                    </div>
                                    <div class="summary-value text-info">
                                        <?= number_format($summary_data['updates']['products_updated'] ?? 0) ?>
                                    </div>
                                    <div class="summary-label">Products Updated</div>
                                    <p class="small text-muted mt-2 mb-0">
                                        <?= number_format($summary_data['updates']['total_updates'] ?? 0) ?> stock updates
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card summary-card border-top border-success">
                                <div class="card-body text-center">
                                    <div class="summary-icon bg-success bg-opacity-10 text-success mx-auto">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                    <div class="summary-value text-success">
                                        <?= number_format($summary_data['sales']['products_sold'] ?? 0) ?>
                                    </div>
                                    <div class="summary-label">Products Sold</div>
                                    <p class="small text-muted mt-2 mb-0">
                                        KSH <?= number_format($summary_data['sales']['total_sales_value'] ?? 0, 2) ?> value
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card summary-card border-top border-warning">
                                <div class="card-body text-center">
                                    <div class="summary-icon bg-warning bg-opacity-10 text-warning mx-auto">
                                        <i class="fas fa-boxes"></i>
                                    </div>
                                    <div class="summary-value text-warning">
                                        <?= number_format($summary_data['current_stock']['current_stock'] ?? 0) ?>
                                    </div>
                                    <div class="summary-label">Current Stock</div>
                                    <p class="small text-muted mt-2 mb-0">
                                        KSH <?= number_format($summary_data['current_stock']['stock_value'] ?? 0, 2) ?> value
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detailed Statistics Grid -->
                    <div class="row mb-4">
                        <!-- Stock Alerts Card -->
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-exclamation-triangle me-2"></i>Stock Alerts
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="stat-detail-card border-left-danger">
                                        <div class="stat-detail-label">Low Stock Items</div>
                                        <div class="stat-detail-value text-danger">
                                            <?= number_format($summary_data['current_stock']['low_stock_items'] ?? 0) ?> items
                                            <?php if ($total_products > 0): ?>
                                                <small class="text-muted">
                                                    (<?= number_format(($summary_data['current_stock']['low_stock_items'] ?? 0) / $total_products * 100, 1) ?>%)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="stat-detail-card border-left-warning">
                                        <div class="stat-detail-label">Out of Stock</div>
                                        <div class="stat-detail-value text-warning">
                                            <?= number_format($summary_data['current_stock']['out_of_stock_items'] ?? 0) ?> items
                                            <?php if ($total_products > 0): ?>
                                                <small class="text-muted">
                                                    (<?= number_format(($summary_data['current_stock']['out_of_stock_items'] ?? 0) / $total_products * 100, 1) ?>%)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="stat-detail-card border-left-success">
                                        <div class="stat-detail-label">Active Products</div>
                                        <div class="stat-detail-value text-success">
                                            <?= number_format($summary_data['current_stock']['active_products'] ?? 0) ?> products
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Stock Movements Card -->
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-arrows-alt-h me-2"></i>Stock Movements
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="stat-detail-card border-left-success">
                                        <div class="stat-detail-label">Stock Added</div>
                                        <div class="stat-detail-value text-success">
                                            +<?= number_format($summary_data['updates']['stock_added'] ?? 0) ?> units
                                        </div>
                                    </div>
                                    <div class="stat-detail-card border-left-danger">
                                        <div class="stat-detail-label">Stock Removed</div>
                                        <div class="stat-detail-value text-danger">
                                            -<?= number_format($summary_data['updates']['stock_removed'] ?? 0) ?> units
                                        </div>
                                    </div>
                                    <div class="stat-detail-card border-left-primary">
                                        <div class="stat-detail-label">Products Sold</div>
                                        <div class="stat-detail-value text-primary">
                                            -<?= number_format($summary_data['sales']['total_quantity_sold'] ?? 0) ?> units
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sales Summary Card -->
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-money-bill-wave me-2"></i>Sales Summary
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="stat-detail-card border-left-success">
                                        <div class="stat-detail-label">Total Sales Value</div>
                                        <div class="stat-detail-value text-success">
                                            KSH <?= number_format($summary_data['sales']['total_sales_value'] ?? 0, 2) ?>
                                        </div>
                                    </div>
                                    <div class="stat-detail-card border-left-primary">
                                        <div class="stat-detail-label">Total Transactions</div>
                                        <div class="stat-detail-value text-primary">
                                            <?= number_format($summary_data['sales']['total_transactions'] ?? 0) ?> transactions
                                        </div>
                                    </div>
                                    <div class="stat-detail-card border-left-info">
                                        <div class="stat-detail-label">Avg. Transaction</div>
                                        <div class="stat-detail-value text-info">
                                            KSH <?= number_format(($summary_data['sales']['total_sales_value'] ?? 0) / max(1, ($summary_data['sales']['total_transactions'] ?? 1)), 2) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Product Details Table with Pagination -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-boxes me-2"></i>Product Details - <?= htmlspecialchars($period_info['period_name'] ?? 'Unnamed Period') ?>
                            </h5>
                            <div class="export-btn-group">
                                <span class="badge bg-light text-dark">
                                    Showing <?= number_format(min($per_page, count($product_details))) ?> of <?= number_format($total_products) ?> products
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover data-table mb-0 table-fixed-layout" id="productsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="25%">Product</th>
                                            <th width="10%">SKU</th>
                                            <th width="10%">Category</th>
                                            <th width="8%">Start Stock</th>
                                            <th width="8%">Added</th>
                                            <th width="8%">Removed</th>
                                            <th width="8%">Sold</th>
                                            <th width="8%">Current</th>
                                            <th width="8%">Status</th>
                                            <th width="7%">Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $month_names = [
                                            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
                                            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
                                            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
                                        ];
                                        
                                        foreach ($product_details as $product): 
                                            $stock_added = $product['stock_added_during_period'] ?? 0;
                                            $stock_removed = $product['stock_removed_during_period'] ?? 0;
                                            $sold = $product['sold_this_period'] ?? 0;
                                            $starting_stock = ($product['current_stock'] + $stock_removed) - $stock_added + $sold;
                                            
                                            // Calculate progress percentage
                                            $max_expected = max($starting_stock, ($product['min_stock'] ?? 0) * 3);
                                            $progress = $max_expected > 0 ? ($product['current_stock'] / $max_expected) * 100 : 0;
                                            
                                            // Determine status
                                            if (($product['current_stock'] ?? 0) == 0) {
                                                $status = 'Out of Stock';
                                                $status_class = 'status-out-of-stock';
                                                $progress_class = 'bg-danger';
                                            } elseif (($product['current_stock'] ?? 0) <= ($product['min_stock'] ?? 0)) {
                                                $status = 'Low Stock';
                                                $status_class = 'status-low-stock';
                                                $progress_class = 'bg-warning';
                                            } else {
                                                $status = 'In Stock';
                                                $status_class = 'status-in-stock';
                                                $progress_class = $progress < 50 ? 'bg-info' : 'bg-success';
                                            }
                                        ?>
                                            <tr>
                                                <td class="nowrap">
                                                    <div class="d-flex align-items-center">
                                                        <div class="product-icon product-icon-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2">
                                                            <i class="fas fa-box"></i>
                                                        </div>
                                                        <div>
                                                            <strong class="d-block text-truncate product-name-truncate" title="<?= htmlspecialchars($product['name'] ?? 'Unknown Product', ENT_QUOTES, 'UTF-8') ?>">
                                                                <?= htmlspecialchars($product['name'] ?? 'Unknown Product', ENT_QUOTES, 'UTF-8') ?>
                                                            </strong>
                                                            <small class="text-muted d-block">
                                                                Cost: KSH <?= number_format($product['cost_price'] ?? 0, 2) ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark text-truncate sku-truncate" title="<?= htmlspecialchars($product['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($product['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info text-truncate category-truncate" title="<?= htmlspecialchars($product['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?= number_format($starting_stock) ?></strong>
                                                </td>
                                                <td class="text-success">
                                                    <i class="fas fa-plus me-1"></i><?= number_format($stock_added) ?>
                                                </td>
                                                <td class="text-danger">
                                                    <i class="fas fa-minus me-1"></i><?= number_format($stock_removed) ?>
                                                </td>
                                                <td class="text-primary">
                                                    <i class="fas fa-shopping-cart me-1"></i><?= number_format($sold) ?>
                                                </td>
                                                <td>
                                                    <strong class="<?= ($product['current_stock'] ?? 0) == 0 ? 'text-danger' : (($product['current_stock'] ?? 0) <= ($product['min_stock'] ?? 0) ? 'text-warning' : 'text-success') ?>">
                                                        <?= number_format($product['current_stock'] ?? 0) ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <span class="product-status-badge <?= $status_class ?>">
                                                        <?= $status ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="stock-progress">
                                                        <div class="progress stock-progress-bar">
                                                            <div class="progress-bar <?= $progress_class ?>" 
                                                                 style="width: <?= min($progress, 100) ?>%"
                                                                 role="progressbar" 
                                                                 aria-valuenow="<?= $progress ?>"
                                                                 aria-valuemin="0" 
                                                                 aria-valuemax="100"
                                                                 title="<?= number_format($progress, 1) ?>%">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($product_details)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4">
                                                    <i class="fas fa-box-open fa-2x text-muted mb-3"></i>
                                                    <h5 class="text-muted">No products found</h5>
                                                    <p class="text-muted">
                                                        <?php if ($search || $category_filter || $stock_filter !== 'all'): ?>
                                                            Try adjusting your filters
                                                        <?php else: ?>
                                                            No products added to this period yet
                                                        <?php endif; ?>
                                                    </p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <div class="card-footer">
                                <nav aria-label="Product pagination">
                                    <ul class="pagination justify-content-center mb-0">
                                        <!-- First Page -->
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>&page=1&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&stock=<?= $stock_filter ?>&csrf_token=<?= $csrf_token ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Previous Page -->
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&stock=<?= $stock_filter ?>&csrf_token=<?= $csrf_token ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Page Numbers -->
                                        <?php
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);
                                        
                                        if ($start_page > 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        
                                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>&page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&stock=<?= $stock_filter ?>&csrf_token=<?= $csrf_token ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor;
                                        
                                        if ($end_page < $total_pages) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        ?>
                                        
                                        <!-- Next Page -->
                                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&stock=<?= $stock_filter ?>&csrf_token=<?= $csrf_token ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        
                                        <!-- Last Page -->
                                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>&page=<?= $total_pages ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&stock=<?= $stock_filter ?>&csrf_token=<?= $csrf_token ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="text-center mt-2">
                                        <small class="text-muted">
                                            Page <?= $page ?> of <?= $total_pages ?> • 
                                            <?= number_format($total_products) ?> total products
                                        </small>
                                    </div>
                                </nav>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Database Index Recommendations -->
                    <?php if (false): // Set to true for debugging ?>
                    <div class="card mb-4 border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="fas fa-database me-2"></i>Performance Recommendations
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-2">For 1000+ products, ensure these database indexes exist:</p>
                            <pre class="bg-light p-3 rounded small">
CREATE INDEX idx_products_period ON products(period_id, is_active);
CREATE INDEX idx_stock_transactions_product_date ON stock_transactions(product_id, created_date);
CREATE INDEX idx_transactions_period ON transactions(time_period_id);
CREATE INDEX idx_transaction_items_transaction ON transaction_items(transaction_id);
CREATE INDEX idx_products_category ON products(category_id);
                            </pre>
                            <p class="mb-0 text-muted small">
                                <i class="fas fa-lightbulb me-1"></i>
                                Consider adding caching for summary statistics using Redis or Memcached
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Quick Links -->
                <div class="card">
                    <div class="card-body">
                        <h5 class="mb-3">
                            <i class="fas fa-link me-2"></i>Quick Links
                        </h5>
                        <div class="row">
                            <div class="col-md-3 mb-2">
                                <a href="products.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-boxes me-2"></i>Products Management
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="pos.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-cash-register me-2"></i>POS System
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="dashboard.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="time_periods.php" class="btn btn-outline-warning w-100">
                                    <i class="fas fa-calendar me-2"></i>Manage Periods
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" 
            integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" 
            crossorigin="anonymous"></script>

    <!-- DataTables for enhanced table features -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert.classList.contains('show')) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }
            }, 5000);
        });
        
        // Show loading overlay during filter changes
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                // Don't show loading for search-only changes
                if (!this.querySelector('[name="search"]') || 
                    this.querySelector('[name="search"]').value === '<?= $search ?>') {
                    document.getElementById('loadingOverlay').classList.add('active');
                }
            });
        }
        
        // Hide loading overlay when page loads
        window.addEventListener('load', function() {
            document.getElementById('loadingOverlay').classList.remove('active');
        });
        
        // Initialize DataTables for better table performance
        const productsTable = document.getElementById('productsTable');
        if (productsTable && <?= count($product_details) ?> > 0) {
            // Only initialize if we have data
            $(productsTable).DataTable({
                pageLength: <?= $per_page ?>,
                lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
                order: [[0, 'asc']],
                searching: false, // We have our own search
                info: false, // We have our own pagination
                paging: false, // We have our own pagination
                autoWidth: false,
                scrollX: true,
                scrollY: '400px',
                scrollCollapse: true,
                deferRender: true, // Improves performance for large datasets
                columnDefs: [
                    { targets: '_all', orderable: true }
                ],
                language: {
                    emptyTable: "No products found",
                    zeroRecords: "No matching products found"
                }
            });
        }
        
        // Virtual scrolling simulation for large tables
        let isLoading = false;
        let currentPage = <?= $page ?>;
        const tableBody = document.querySelector('#productsTable tbody');
        
        if (tableBody && <?= $total_pages ?> > 1) {
            window.addEventListener('scroll', function() {
                if (isLoading || currentPage >= <?= $total_pages ?>) return;
                
                const scrollPosition = window.innerHeight + window.scrollY;
                const pageBottom = document.body.offsetHeight - 100; // Load 100px before bottom
                
                if (scrollPosition >= pageBottom) {
                    isLoading = true;
                    currentPage++;
                    
                    // Show loading indicator
                    const loadingRow = document.createElement('tr');
                    loadingRow.innerHTML = `
                        <td colspan="10" class="text-center py-3">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading more products...</span>
                            </div>
                            <span class="ms-2">Loading more products...</span>
                        </td>
                    `;
                    tableBody.appendChild(loadingRow);
                    
                    // Load next page via AJAX
                    fetch(`?period_id=<?= $selected_period_id ?>&year=<?= $year_filter ?>&page=${currentPage}&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&stock=<?= $stock_filter ?>&ajax=1`)
                        .then(response => response.text())
                        .then(html => {
                            // Parse the HTML and extract table rows
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newRows = doc.querySelectorAll('#productsTable tbody tr');
                            
                            // Remove loading row
                            tableBody.removeChild(loadingRow);
                            
                            // Add new rows
                            newRows.forEach(row => {
                                if (!row.querySelector('.spinner-border')) {
                                    tableBody.appendChild(row);
                                }
                            });
                            
                            isLoading = false;
                        })
                        .catch(error => {
                            console.error('Error loading more products:', error);
                            tableBody.removeChild(loadingRow);
                            isLoading = false;
                        });
                }
            });
        }
        
        // Quick filter buttons
        const quickFilterButtons = document.querySelectorAll('.quick-filter');
        quickFilterButtons.forEach(button => {
            button.addEventListener('click', function() {
                const filterType = this.dataset.filter;
                const filterForm = document.getElementById('filterForm');
                
                switch(filterType) {
                    case 'low-stock':
                        filterForm.querySelector('[name="stock"]').value = 'low';
                        break;
                    case 'out-of-stock':
                        filterForm.querySelector('[name="stock"]').value = 'out';
                        break;
                    case 'high-value':
                        // This would require custom sorting
                        break;
                }
                
                filterForm.querySelector('[name="page"]').value = 1;
                filterForm.submit();
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('[name="search"]');
                if (searchInput) searchInput.focus();
            }
            
            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('[name="search"]');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    filterForm.submit();
                }
            }
        });
        
        // Performance monitoring
        const perfMark = 'pageLoaded';
        if (performance.mark) {
            performance.mark(perfMark);
            performance.measure('pageLoadTime', perfMark);
            const measures = performance.getEntriesByName('pageLoadTime');
            if (measures.length > 0) {
                console.log(`Page loaded in ${measures[0].duration.toFixed(2)}ms`);
            }
        }
    });
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($db)) {
    $db->close();
}