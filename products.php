<?php
// Enhanced error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

session_start();

// Security Headers - UPDATED CSP with proper directives
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Include configuration
require_once 'config.php';
require_once 'functions.php';

/* ----------------------------
    SECURITY FUNCTIONS
-----------------------------*/

function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || 
        !isset($_SESSION['user_ip']) || 
        !isset($_SESSION['user_agent']) ||
        !isset($_SESSION['login_time']) ||
        !isset($_SESSION['role'])) {
        return false;
    }
    
    // IP address validation (allow some flexibility for proxies)
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($_SESSION['user_ip'] !== $current_ip) {
        error_log("Security: IP mismatch for user ID " . ($_SESSION['user_id'] ?? 'unknown'));
        // Don't destroy immediately - could be legitimate proxy use
    }
    
    // User agent validation
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if ($_SESSION['user_agent'] !== $current_ua) {
        error_log("Security: User agent changed for user ID " . ($_SESSION['user_id'] ?? 'unknown'));
    }
    
    // Session timeout (1 hour)
    if (time() - $_SESSION['login_time'] > 3600) {
        session_destroy();
        return false;
    }
    
    // Update last activity
    $_SESSION['login_time'] = time();
    
    return true;
}

function isSeller() {
    return isLoggedIn() && isset($_SESSION['role']) && 
           ($_SESSION['role'] === 'seller' || $_SESSION['role'] === 'admin');
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        error_log("DEBUG: CSRF token missing. Session token: " . ($_SESSION['csrf_token'] ?? 'empty') . ", Form token: " . ($token ?? 'empty'));
        return false;
    }
    
    $result = hash_equals($_SESSION['csrf_token'], $token);
    if (!$result) {
        error_log("DEBUG: CSRF token mismatch. Session: " . $_SESSION['csrf_token'] . ", Form: $token");
    }
    return $result;
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || 
        empty($_SESSION['csrf_token_time']) || 
        time() - $_SESSION['csrf_token_time'] > 3600) {
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        error_log("DEBUG: New CSRF token generated: " . $_SESSION['csrf_token']);
    }
    return $_SESSION['csrf_token'];
}

function sanitizeInput($data, $type = 'string') {
    if (!is_string($data)) {
        return '';
    }
    
    $data = trim($data);
    $data = strip_tags($data);
    
    switch ($type) {
        case 'email':
            $data = filter_var($data, FILTER_SANITIZE_EMAIL);
            break;
        case 'int':
            $data = filter_var($data, FILTER_SANITIZE_NUMBER_INT);
            break;
        case 'float':
            $data = filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            break;
        case 'string':
        default:
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            break;
    }
    
    return $data;
}

function validateInteger($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    $value = intval($value);
    
    if ($min !== null && $value < $min) {
        return false;
    }
    
    if ($max !== null && $value > $max) {
        return false;
    }
    
    return true;
}

function validateFloat($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    $value = floatval($value);
    
    if ($min !== null && $value < $min) {
        return false;
    }
    
    if ($max !== null && $value > $max) {
        return false;
    }
    
    return true;
}

// Check authentication
if (!isLoggedIn() || !isSeller()) {
    header("Location: login.php");
    exit();
}

// Generate CSRF token
$csrf_token = generateCSRFToken();

// Database connection with error handling
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Test connection
    if (!$db->ping()) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'unknown';
$message = '';
$error = '';
$edit_mode = false;
$edit_product = null;
$category_message = '';
$category_error = '';
$selected_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;

// Validate period_id parameter
if ($selected_period_id && !validateInteger($selected_period_id, 1)) {
    $selected_period_id = null;
}

// Security: Log page access to error log
error_log("DEBUG: User ID $user_id accessed products management page from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

/* -------------------------------------------------------
   DATABASE SCHEMA CHECK WITH SECURITY
-------------------------------------------------------- */

function checkPeriodColumnsExist($db) {
    try {
        $sql = "SHOW COLUMNS FROM products LIKE 'period_id'";
        $result = $db->query($sql);
        return $result && $result->num_rows > 0;
    } catch (Exception $e) {
        error_log("Schema check error: " . $e->getMessage());
        return false;
    }
}

$period_tracking_enabled = checkPeriodColumnsExist($db);

/* -------------------------------------------------------
   AUTO-GENERATE SKU FUNCTION WITH VALIDATION
-------------------------------------------------------- */

function generateUniqueSKU($name, $category_id, $db) {
    // Validate inputs
    if (empty($name) || !validateInteger($category_id, 1)) {
        return 'GEN-' . time();
    }
    
    $name = substr(trim($name), 0, 100);
    
    try {
        // Get category abbreviation with prepared statement
        $category_sql = "SELECT name FROM categories WHERE id = ?";
        $category_stmt = $db->prepare($category_sql);
        $category_stmt->bind_param("i", $category_id);
        $category_stmt->execute();
        $category_result = $category_stmt->get_result();
        $category = $category_result->fetch_assoc();
        
        $category_abbr = 'GEN';
        if ($category) {
            $words = explode(' ', $category['name']);
            $abbr = '';
            foreach ($words as $word) {
                if (ctype_alpha($word)) {
                    $abbr .= strtoupper(substr($word, 0, 1));
                }
            }
            $category_abbr = substr($abbr, 0, 3) ?: 'GEN';
        }
        
        // Get product name abbreviation
        $name_words = explode(' ', $name);
        $name_abbr = '';
        foreach ($name_words as $word) {
            if (ctype_alpha($word)) {
                $name_abbr .= strtoupper(substr($word, 0, 1));
            }
        }
        $name_abbr = substr($name_abbr, 0, 3) ?: 'PROD';
        
        // Generate base SKU
        $base_sku = $category_abbr . '-' . $name_abbr;
        
        // Check if SKU exists and find next available number
        $counter = 1;
        $sku = $base_sku . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
        
        $check_sql = "SELECT id FROM products WHERE sku = ?";
        $check_stmt = $db->prepare($check_sql);
        
        while ($counter <= 999) {
            $check_stmt->bind_param("s", $sku);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows === 0) {
                return $sku; // SKU is unique
            }
            
            $counter++;
            $sku = $base_sku . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
        }
        
        // Fallback with timestamp
        return $base_sku . '-' . time();
        
    } catch (Exception $e) {
        error_log("SKU generation error: " . $e->getMessage());
        return 'GEN-' . time();
    }
}

/* -------------------------------------------------------
   CATEGORIES HANDLING WITH SECURITY
-------------------------------------------------------- */

function fetchCategories($db) {
    $categories = [];
    try {
        $category_sql = "SELECT id, name FROM categories ORDER BY name";
        $category_result = $db->query($category_sql);

        if ($category_result && $category_result->num_rows > 0) {
            while ($row = $category_result->fetch_assoc()) {
                $categories[(int)$row['id']] = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
            }
        }
    } catch (Exception $e) {
        error_log("Category fetch error: " . $e->getMessage());
    }
    return $categories;
}

// Handle category creation with security - SEPARATED FROM PRODUCT HANDLING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $category_error = "Invalid form submission. Please try again.";
        error_log("Security: Category creation CSRF token invalid for user ID $user_id");
    } else {
        $category_name = sanitizeInput($_POST['category_name'] ?? '', 'string');
        
        if (empty($category_name) || strlen($category_name) > 100) {
            $category_error = "Category name must be between 1 and 100 characters.";
        } else {
            // Check if category already exists
            $check_sql = "SELECT id FROM categories WHERE name = ?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->bind_param("s", $category_name);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $category_error = "Category '$category_name' already exists!";
            } else {
                // Insert new category - FIXED: Changed 'product' to 'prod' to match column size
                $insert_sql = "INSERT INTO categories (name, type, created_at) VALUES (?, 'prod', NOW())";
                $insert_stmt = $db->prepare($insert_sql);
                $insert_stmt->bind_param("s", $category_name);
                
                if ($insert_stmt->execute()) {
                    $category_message = "Category '$category_name' added successfully!";
                    error_log("Security: User ID $user_id created category: $category_name");
                    // Clear POST data to prevent product form processing
                    unset($_POST['name'], $_POST['sku'], $_POST['category_id'], $_POST['cost_price'], 
                          $_POST['selling_price'], $_POST['stock_quantity'], $_POST['min_stock'], 
                          $_POST['supplier'], $_POST['description']);
                } else {
                    $category_error = "Failed to add category. Please try again.";
                    error_log("Security: User ID $user_id failed to create category: $category_name. Error: " . $insert_stmt->error);
                }
            }
        }
    }
}

// Handle category deletion with security
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $category_error = "Invalid form submission. Please try again.";
        error_log("Security: Category deletion CSRF token invalid for user ID $user_id");
    } else {
        $category_id = intval($_POST['category_id'] ?? 0);
        
        if (!validateInteger($category_id, 1)) {
            $category_error = "Invalid category ID.";
        } else {
            // Check if category is used by any products
            $check_sql = "SELECT COUNT(*) as product_count FROM products WHERE category_id = ?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->bind_param("i", $category_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $product_count = $result->fetch_assoc()['product_count'];
            
            if ($product_count > 0) {
                $category_error = "Cannot delete category: It is used by $product_count product(s).";
            } else {
                $delete_sql = "DELETE FROM categories WHERE id = ?";
                $delete_stmt = $db->prepare($delete_sql);
                $delete_stmt->bind_param("i", $category_id);
                
                if ($delete_stmt->execute()) {
                    $category_message = "Category deleted successfully!";
                    error_log("Security: User ID $user_id deleted category ID: $category_id");
                } else {
                    $category_error = "Failed to delete category. Please try again.";
                }
            }
        }
    }
}

// Fetch current categories
$categories = fetchCategories($db);

/* -------------------------------------------------------
   FETCH ALL PERIODS FOR FILTERING WITH VALIDATION
-------------------------------------------------------- */

$periods = [];
if ($period_tracking_enabled) {
    try {
        $period_sql = "SELECT id, period_name, year, month, is_locked FROM time_periods ORDER BY year DESC, month DESC";
        $period_result = $db->query($period_sql);
        while ($period = $period_result->fetch_assoc()) {
            $periods[(int)$period['id']] = [
                'period_name' => htmlspecialchars($period['period_name'], ENT_QUOTES, 'UTF-8'),
                'year' => (int)$period['year'],
                'month' => (int)$period['month'],
                'is_locked' => (bool)$period['is_locked']
            ];
        }
    } catch (Exception $e) {
        error_log("Period fetch error: " . $e->getMessage());
    }
}

// Get current active period with security check
$current_period = null;
if ($period_tracking_enabled) {
    try {
        $period_sql = "SELECT * FROM time_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
        $period_result = $db->query($period_sql);
        if ($period_result && $period_result->num_rows > 0) {
            $current_period = $period_result->fetch_assoc();
        }
    } catch (Exception $e) {
        error_log("Current period fetch error: " . $e->getMessage());
    }
}

/* -------------------------------------------------------
   INVENTORY MANAGEMENT FUNCTION
-------------------------------------------------------- */

function recordInventoryTransaction($db, $product_id, $transaction_type, $quantity, $notes = '', $user_id = null) {
    try {
        // Get product details
        $product_sql = "SELECT name, cost_price, selling_price, period_id FROM products WHERE id = ?";
        $product_stmt = $db->prepare($product_sql);
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product = $product_stmt->get_result()->fetch_assoc();
        
        if (!$product) {
            return false;
        }
        
        // Calculate transaction value
        $unit_price = $product['cost_price']; // Using cost price for inventory valuation
        $total_value = $unit_price * $quantity;
        
        // Insert inventory transaction
        $sql = "INSERT INTO inventory_transactions 
                (product_id, product_name, transaction_type, quantity, unit_price, total_value, 
                 notes, user_id, period_id, transaction_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("issiddsii", 
            $product_id, 
            $product['name'],
            $transaction_type,
            $quantity,
            $unit_price,
            $total_value,
            $notes,
            $user_id,
            $product['period_id']
        );
        
        return $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Inventory transaction error: " . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------
   DEBUG: LOG FORM SUBMISSION
-------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("DEBUG: POST request received on products.php");
    error_log("DEBUG: POST data: " . print_r($_POST, true));
    
    // Check which form was submitted
    if (isset($_POST['add_product'])) {
        error_log("DEBUG: 'add_product' form submitted");
    }
    if (isset($_POST['update_product'])) {
        error_log("DEBUG: 'update_product' form submitted");
    }
    if (isset($_POST['add_category'])) {
        error_log("DEBUG: 'add_category' form submitted");
    }
}

/* -------------------------------------------------------
   HANDLE PRODUCT FORM SUBMISSIONS WITH SECURITY - FIXED SKU VALIDATION
-------------------------------------------------------- */

// Handle product creation/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product']) || isset($_POST['update_product'])) {
        error_log("DEBUG: Processing product form submission");
        
        // CSRF validation
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf_token)) {
            $error = "Invalid form submission. Please try again.";
            error_log("Security: Product form CSRF token invalid for user ID $user_id");
        } else {
            error_log("DEBUG: CSRF validation passed");
            
            // Sanitize and validate all inputs
            $name = sanitizeInput($_POST['name'] ?? '', 'string');
            $sku = sanitizeInput($_POST['sku'] ?? '', 'string');
            $category_id = intval($_POST['category_id'] ?? 0);
            $cost_price = floatval($_POST['cost_price'] ?? 0);
            $selling_price = floatval($_POST['selling_price'] ?? 0);
            $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
            $min_stock = intval($_POST['min_stock'] ?? 5);
            $supplier = sanitizeInput($_POST['supplier'] ?? '', 'string');
            $description = sanitizeInput($_POST['description'] ?? '', 'string');
            $is_update = isset($_POST['update_product']);
            $product_id = $is_update ? intval($_POST['product_id'] ?? 0) : null;

            error_log("DEBUG: Form data extracted:");
            error_log("Name: $name, SKU: $sku, Category: $category_id");
            error_log("Cost: $cost_price, Selling: $selling_price, Stock: $stock_quantity");
            error_log("Update mode: " . ($is_update ? 'YES' : 'NO'));

            // Validate required fields
            $validation_errors = [];
            
            if (empty($name) || strlen($name) > 255) {
                $validation_errors[] = "Product name must be 1-255 characters.";
            }
            
            // FIX: Only validate SKU length if it's provided and not empty
            if (!empty($sku) && strlen($sku) > 100) {
                $validation_errors[] = "SKU must be maximum 100 characters if provided.";
            }
            
            if (!array_key_exists($category_id, $categories)) {
                $validation_errors[] = "Invalid category selected.";
            }
            
            if (!validateFloat($cost_price, 0, 9999999.99)) {
                $validation_errors[] = "Invalid cost price.";
            }
            
            if (!validateFloat($selling_price, 0, 9999999.99)) {
                $validation_errors[] = "Invalid selling price.";
            }
            
            if ($selling_price < $cost_price) {
                $validation_errors[] = "Selling price cannot be less than cost price.";
            }
            
            if (!validateInteger($stock_quantity, 0, 999999)) {
                $validation_errors[] = "Invalid stock quantity.";
            }
            
            if (!validateInteger($min_stock, 0, 999999)) {
                $validation_errors[] = "Invalid minimum stock.";
            }
            
            if (strlen($supplier) > 255) {
                $validation_errors[] = "Supplier name too long.";
            }
            
            if (strlen($description) > 1000) {
                $validation_errors[] = "Description too long.";
            }
            
            if ($is_update && !validateInteger($product_id, 1)) {
                $validation_errors[] = "Invalid product ID.";
            }

            if (!empty($validation_errors)) {
                $error = implode(" ", $validation_errors);
                error_log("DEBUG: Validation errors: " . $error);
            } else {
                try {
                    if ($is_update) {
                        // UPDATE EXISTING PRODUCT with ownership check
                        error_log("DEBUG: Attempting to update product ID: $product_id");
                        
                        // First check if product exists and user has permission
                        $check_sql = "SELECT p.id, p.stock_quantity as old_quantity FROM products p 
                                     LEFT JOIN time_periods tp ON p.period_id = tp.id 
                                     WHERE p.id = ? 
                                     AND (p.period_id IS NULL OR tp.created_by = ?)";
                        $check_stmt = $db->prepare($check_sql);
                        $check_stmt->bind_param("ii", $product_id, $user_id);
                        $check_stmt->execute();
                        $product_data = $check_stmt->get_result()->fetch_assoc();
                        
                        if (!$product_data) {
                            $error = "Product not found or access denied.";
                            error_log("DEBUG: Product not found or no permission");
                        } else {
                            // For updates, SKU is required
                            if (empty($sku)) {
                                $error = "SKU is required when updating a product.";
                                error_log("DEBUG: SKU missing for update");
                            } else {
                                // Check if SKU already exists (excluding current product)
                                $check_sql = "SELECT id FROM products WHERE sku = ? AND id != ?";
                                $check_stmt = $db->prepare($check_sql);
                                $check_stmt->bind_param("si", $sku, $product_id);
                                $check_stmt->execute();
                                
                                if ($check_stmt->get_result()->num_rows > 0) {
                                    $error = "Another product with SKU '$sku' already exists!";
                                    error_log("DEBUG: SKU already exists: $sku");
                                } else {
                                    // Calculate stock difference for inventory tracking
                                    $old_quantity = $product_data['old_quantity'];
                                    $quantity_difference = $stock_quantity - $old_quantity;
                                    
                                    // Update product
                                    $sql = "UPDATE products SET name = ?, sku = ?, category_id = ?, cost_price = ?, 
                                            selling_price = ?, stock_quantity = ?, min_stock = ?, supplier = ?, 
                                            description = ?, updated_at = NOW() 
                                            WHERE id = ?";
                                    
                                    $stmt = $db->prepare($sql);
                                    $stmt->bind_param(
                                        "ssiddiissi", 
                                        $name, $sku, $category_id, $cost_price, 
                                        $selling_price, $stock_quantity, $min_stock, $supplier, 
                                        $description, $product_id
                                    );
                                    
                                    if ($stmt->execute()) {
                                        // Record inventory transaction if quantity changed
                                        if ($quantity_difference != 0) {
                                            $transaction_type = $quantity_difference > 0 ? 'STOCK_ADJUSTMENT_ADD' : 'STOCK_ADJUSTMENT_REMOVE';
                                            $notes = "Manual stock adjustment during product update. Old quantity: $old_quantity, New quantity: $stock_quantity";
                                            recordInventoryTransaction($db, $product_id, $transaction_type, abs($quantity_difference), $notes, $user_id);
                                        }
                                        
                                        $message = "Product updated successfully!" . ($quantity_difference != 0 ? " Inventory transaction recorded." : "");
                                        error_log("DEBUG: Product updated successfully");
                                        $edit_mode = false;
                                        $edit_product = null;
                                    } else {
                                        $error = "Failed to update product. Database error: " . $stmt->error;
                                        error_log("DEBUG: Update failed: " . $stmt->error);
                                    }
                                }
                            }
                        }
                    } else {
                        // ADD NEW PRODUCT OR UPDATE INVENTORY
                        error_log("DEBUG: Attempting to add new product");
                        $target_period_id = $current_period ? $current_period['id'] : ($selected_period_id ?: null);
                        
                        // Check period ownership if period tracking is enabled
                        if ($period_tracking_enabled && $target_period_id) {
                            $period_check_sql = "SELECT id FROM time_periods WHERE id = ? AND created_by = ?";
                            $period_check_stmt = $db->prepare($period_check_sql);
                            $period_check_stmt->bind_param("ii", $target_period_id, $user_id);
                            $period_check_stmt->execute();
                            
                            if ($period_check_stmt->get_result()->num_rows === 0) {
                                $error = "Invalid period or access denied.";
                                error_log("DEBUG: No permission for period ID: $target_period_id");
                            }
                        }
                        
                        if (!$error) {
                            // Auto-generate SKU if empty
                            if (empty($sku)) {
                                $sku = generateUniqueSKU($name, $category_id, $db);
                                error_log("DEBUG: Auto-generated SKU: $sku");
                            }
                            
                            // Check if product with same name and SKU exists in the same period
                            if ($target_period_id) {
                                $check_sql = "SELECT id, stock_quantity FROM products WHERE name = ? AND sku = ? AND period_id = ?";
                                $check_stmt = $db->prepare($check_sql);
                                $check_stmt->bind_param("ssi", $name, $sku, $target_period_id);
                            } else {
                                $check_sql = "SELECT id, stock_quantity FROM products WHERE name = ? AND sku = ? AND period_id IS NULL";
                                $check_stmt = $db->prepare($check_sql);
                                $check_stmt->bind_param("ss", $name, $sku);
                            }
                            
                            $check_stmt->execute();
                            $existing_product = $check_stmt->get_result()->fetch_assoc();
                            
                            if ($existing_product) {
                                // PRODUCT EXISTS - UPDATE INVENTORY
                                error_log("DEBUG: Product exists, updating inventory");
                                $old_quantity = $existing_product['stock_quantity'];
                                $new_stock_quantity = $old_quantity + $stock_quantity;
                                
                                $update_sql = "UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?";
                                $update_stmt = $db->prepare($update_sql);
                                $update_stmt->bind_param("ii", $new_stock_quantity, $existing_product['id']);
                                
                                if ($update_stmt->execute()) {
                                    // Record inventory transaction
                                    $notes = "Stock addition through product management. Added: $stock_quantity units";
                                    recordInventoryTransaction($db, $existing_product['id'], 'STOCK_ADDITION', $stock_quantity, $notes, $user_id);
                                    
                                    $message = "Inventory updated successfully! Stock for '{$name}' increased from {$old_quantity} to {$new_stock_quantity}. Inventory transaction recorded.";
                                    error_log("DEBUG: Inventory updated successfully");
                                    $_POST = array(); // Clear form
                                } else {
                                    $error = "Failed to update inventory. Please try again.";
                                    error_log("DEBUG: Inventory update failed: " . $update_stmt->error);
                                }
                            } else {
                                // NEW PRODUCT - CREATE
                                error_log("DEBUG: Creating new product");
                                // Check if manually entered SKU already exists in same period
                                if ($target_period_id) {
                                    $check_sku_sql = "SELECT id FROM products WHERE sku = ? AND period_id = ?";
                                    $check_sku_stmt = $db->prepare($check_sku_sql);
                                    $check_sku_stmt->bind_param("si", $sku, $target_period_id);
                                } else {
                                    $check_sku_sql = "SELECT id FROM products WHERE sku = ? AND period_id IS NULL";
                                    $check_sku_stmt = $db->prepare($check_sku_sql);
                                    $check_sku_stmt->bind_param("s", $sku);
                                }
                                
                                $check_sku_stmt->execute();
                                
                                if ($check_sku_stmt->get_result()->num_rows > 0) {
                                    $error = "Product with SKU '$sku' already exists in this period!";
                                    error_log("DEBUG: SKU already exists: $sku");
                                }
                                
                                if (!$error) {
                                    // Build SQL based on period tracking
                                    if ($period_tracking_enabled && $target_period_id) {
                                        $sql = "INSERT INTO products (name, sku, category_id, cost_price, selling_price, 
                                                stock_quantity, min_stock, supplier, description,
                                                period_id, period_year, period_month, added_date, is_active) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1)";
                                        
                                        $stmt = $db->prepare($sql);
                                        
                                        if (!$stmt) {
                                            error_log("DEBUG: Prepare failed: " . $db->error);
                                            throw new Exception("Database prepare failed: " . $db->error);
                                        }
                                        
                                        // Get period details
                                        $period_details_sql = "SELECT year, month FROM time_periods WHERE id = ?";
                                        $period_details_stmt = $db->prepare($period_details_sql);
                                        $period_details_stmt->bind_param("i", $target_period_id);
                                        $period_details_stmt->execute();
                                        $period_details = $period_details_stmt->get_result()->fetch_assoc();
                                        
                                        $period_year = $period_details['year'] ?? date('Y');
                                        $period_month = $period_details['month'] ?? date('m');
                                        
                                        // Debug logging to check parameters
                                        error_log("DEBUG - Product Insert Parameters:");
                                        error_log("Name: $name, SKU: $sku, Category: $category_id");
                                        error_log("Cost: $cost_price, Selling: $selling_price, Stock: $stock_quantity");
                                        error_log("Min Stock: $min_stock, Supplier: $supplier");
                                        error_log("Period ID: $target_period_id, Year: $period_year, Month: $period_month");
                                        
                                        // Verify all required parameters are set
                                        $required_params = [$name, $sku, $category_id, $cost_price, $selling_price, 
                                                           $stock_quantity, $min_stock, $supplier, $description, 
                                                           $target_period_id, $period_year, $period_month];
                                        
                                        $all_set = true;
                                        foreach ($required_params as $index => $param) {
                                            if ($param === null || $param === '') {
                                                error_log("DEBUG - Parameter $index is empty/null: " . $param);
                                                $all_set = false;
                                            }
                                        }
                                        
                                        if (!$all_set) {
                                            throw new Exception("One or more required parameters are empty");
                                        }
                                        
                                        // CORRECTED: Fixed parameter types and count
                                        $bind_result = $stmt->bind_param("ssiddiiisiii", 
                                            $name, 
                                            $sku, 
                                            $category_id, 
                                            $cost_price, 
                                            $selling_price, 
                                            $stock_quantity, 
                                            $min_stock, 
                                            $supplier, 
                                            $description, 
                                            $target_period_id, 
                                            $period_year, 
                                            $period_month
                                        );
                                        
                                        if (!$bind_result) {
                                            throw new Exception("Failed to bind parameters: " . $stmt->error);
                                        }
                                    } else {
                                        // Fallback without period tracking
                                        $sql = "INSERT INTO products (name, sku, category_id, cost_price, selling_price, 
                                                stock_quantity, min_stock, supplier, description, is_active) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                                        
                                        $stmt = $db->prepare($sql);
                                        
                                        if (!$stmt) {
                                            error_log("DEBUG: Prepare failed: " . $db->error);
                                            throw new Exception("Database prepare failed: " . $db->error);
                                        }
                                        
                                        $bind_result = $stmt->bind_param("ssiddiiss", 
                                            $name, 
                                            $sku, 
                                            $category_id, 
                                            $cost_price, 
                                            $selling_price, 
                                            $stock_quantity, 
                                            $min_stock, 
                                            $supplier, 
                                            $description
                                        );
                                        
                                        if (!$bind_result) {
                                            throw new Exception("Failed to bind parameters: " . $stmt->error);
                                        }
                                    }
                                    
                                    if ($stmt->execute()) {
                                        $new_product_id = $db->insert_id;
                                        error_log("DEBUG: Product inserted successfully. ID: $new_product_id");
                                        
                                        // Record initial inventory transaction
                                        if ($stock_quantity > 0) {
                                            $notes = "Initial stock creation. Quantity: $stock_quantity";
                                            recordInventoryTransaction($db, $new_product_id, 'STOCK_CREATION', $stock_quantity, $notes, $user_id);
                                        }
                                        
                                        if ($period_tracking_enabled && $target_period_id && isset($current_period['period_name'])) {
                                            $message = "Product added successfully for period: " . $current_period['period_name'] . "! SKU: {$sku}" . ($stock_quantity > 0 ? " Initial inventory recorded." : "");
                                        } else {
                                            $message = "Product added successfully! SKU: {$sku}" . ($stock_quantity > 0 ? " Initial inventory recorded." : "");
                                        }
                                        error_log("DEBUG: " . $message);
                                        $_POST = array();
                                    } else {
                                        error_log("DEBUG: Execute failed: " . $stmt->error);
                                        $error = "Failed to add product. Database error: " . $stmt->error;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("DEBUG: Exception in product operation: " . $e->getMessage());
                    $error = "An error occurred: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle product deletion with security
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid form submission. Please try again.";
        error_log("Security: Product deletion CSRF token invalid for user ID $user_id");
    } else {
        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!validateInteger($product_id, 1)) {
            $error = "Invalid product ID.";
        } else {
            try {
                // Check if product exists and user has permission
                $check_sql = "SELECT p.id, p.name, p.period_id, tp.created_by, p.stock_quantity 
                             FROM products p 
                             LEFT JOIN time_periods tp ON p.period_id = tp.id 
                             WHERE p.id = ? 
                             AND p.is_active = 1";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->bind_param("i", $product_id);
                $check_stmt->execute();
                $product_data = $check_stmt->get_result()->fetch_assoc();
                
                if (!$product_data) {
                    $error = "Product not found or already inactive!";
                } elseif ($product_data['period_id'] && $product_data['created_by'] != $user_id) {
                    $error = "Access denied. You don't have permission to delete this product.";
                    error_log("Security: User ID $user_id attempted unauthorized deletion of product ID: $product_id");
                } else {
                    // Check if period is locked
                    if ($product_data['period_id']) {
                        $period_check_sql = "SELECT is_locked FROM time_periods WHERE id = ?";
                        $period_check_stmt = $db->prepare($period_check_sql);
                        $period_check_stmt->bind_param("i", $product_data['period_id']);
                        $period_check_stmt->execute();
                        $period_data = $period_check_stmt->get_result()->fetch_assoc();
                        
                        if ($period_data && $period_data['is_locked']) {
                            $error = "Cannot delete product from a locked period.";
                        }
                    }
                    
                    if (!$error) {
                        // Record inventory removal before soft delete if stock exists
                        if ($product_data['stock_quantity'] > 0) {
                            $notes = "Product deactivation - Stock removal";
                            recordInventoryTransaction($db, $product_id, 'STOCK_REMOVAL', $product_data['stock_quantity'], $notes, $user_id);
                        }
                        
                        // Soft delete (set is_active = 0)
                        $sql = "UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param("i", $product_id);
                        
                        if ($stmt->execute()) {
                            $message = "Product deactivated successfully!" . ($product_data['stock_quantity'] > 0 ? " Inventory removal recorded." : "");
                            error_log("Security: User ID $user_id deleted product ID: $product_id");
                        } else {
                            $error = "Failed to delete product. Please try again.";
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Product deletion error: " . $e->getMessage());
                $error = "An error occurred. Please try again.";
            }
        }
    }
}

// Handle stock update for low stock items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    // CSRF validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid form submission. Please try again.";
        error_log("Security: Stock update CSRF token invalid for user ID $user_id");
    } else {
        $product_id = intval($_POST['product_id'] ?? 0);
        $add_quantity = intval($_POST['add_quantity'] ?? 0);
        
        if (!validateInteger($product_id, 1) || !validateInteger($add_quantity, 1, 999999)) {
            $error = "Invalid product ID or quantity.";
        } else {
            try {
                // Check if product exists and user has permission
                $check_sql = "SELECT p.id, p.name, p.stock_quantity, p.period_id, tp.created_by 
                             FROM products p 
                             LEFT JOIN time_periods tp ON p.period_id = tp.id 
                             WHERE p.id = ? 
                             AND p.is_active = 1";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->bind_param("i", $product_id);
                $check_stmt->execute();
                $product_data = $check_stmt->get_result()->fetch_assoc();
                
                if (!$product_data) {
                    $error = "Product not found or inactive!";
                } elseif ($product_data['period_id'] && $product_data['created_by'] != $user_id) {
                    $error = "Access denied. You don't have permission to update this product.";
                    error_log("Security: User ID $user_id attempted unauthorized stock update for product ID: $product_id");
                } else {
                    // Check if period is locked
                    if ($product_data['period_id']) {
                        $period_check_sql = "SELECT is_locked FROM time_periods WHERE id = ?";
                        $period_check_stmt = $db->prepare($period_check_sql);
                        $period_check_stmt->bind_param("i", $product_data['period_id']);
                        $period_check_stmt->execute();
                        $period_data = $period_check_stmt->get_result()->fetch_assoc();
                        
                        if ($period_data && $period_data['is_locked']) {
                            $error = "Cannot update stock for a locked period.";
                        }
                    }
                    
                    if (!$error) {
                        $new_quantity = $product_data['stock_quantity'] + $add_quantity;
                        
                        // Update stock
                        $update_sql = "UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?";
                        $update_stmt = $db->prepare($update_sql);
                        $update_stmt->bind_param("ii", $new_quantity, $product_id);
                        
                        if ($update_stmt->execute()) {
                            // Record inventory transaction
                            $notes = "Stock replenishment for low stock item. Added: $add_quantity units";
                            recordInventoryTransaction($db, $product_id, 'STOCK_REPLENISHMENT', $add_quantity, $notes, $user_id);
                            
                            $message = "Stock updated successfully! '{$product_data['name']}' stock increased from {$product_data['stock_quantity']} to {$new_quantity}. Inventory transaction recorded.";
                            error_log("Security: User ID $user_id updated stock for product ID: $product_id");
                        } else {
                            $error = "Failed to update stock. Please try again.";
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Stock update error: " . $e->getMessage());
                $error = "An error occurred. Please try again.";
            }
        }
    }
}

// Handle edit request with security
if (isset($_GET['edit'])) {
    $product_id = intval($_GET['edit']);
    
    if (validateInteger($product_id, 1)) {
        try {
            $sql = "SELECT p.* FROM products p 
                   LEFT JOIN time_periods tp ON p.period_id = tp.id 
                   WHERE p.id = ? 
                   AND p.is_active = 1
                   AND (p.period_id IS NULL OR tp.created_by = ?)";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ii", $product_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $edit_product = $result->fetch_assoc();
                $edit_mode = true;
                error_log("Security: User ID $user_id viewing product ID: $product_id for editing");
            } else {
                $error = "Product not found, inactive, or access denied!";
                error_log("Security: User ID $user_id attempted unauthorized edit of product ID: $product_id");
            }
        } catch (Exception $e) {
            error_log("Product edit fetch error: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    } else {
        $error = "Invalid product ID.";
    }
}

// Handle cancel edit
if (isset($_GET['cancel_edit'])) {
    $edit_mode = false;
    $edit_product = null;
}

/* -------------------------------------------------------
   FETCH PRODUCTS AND CALCULATE STATISTICS WITH SECURITY
-------------------------------------------------------- */

$products = [];
$total_products = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;
$total_stock_value = 0;
$products_by_period = [];

try {
    // Build query based on period filter with user permission check
    $where_conditions = ["p.is_active = 1"];
    $params = [];
    $param_types = "";
    
    if ($selected_period_id) {
        $where_conditions[] = "p.period_id = ?";
        $params[] = $selected_period_id;
        $param_types .= "i";
        
        // Verify user owns this period
        $period_check_sql = "SELECT id FROM time_periods WHERE id = ? AND created_by = ?";
        $period_check_stmt = $db->prepare($period_check_sql);
        $period_check_stmt->bind_param("ii", $selected_period_id, $user_id);
        $period_check_stmt->execute();
        
        if ($period_check_stmt->get_result()->num_rows === 0) {
            $error = "Access denied to selected period.";
            $selected_period_id = null;
        }
    }
    
    $sql = "SELECT p.*, c.name as category_name, tp.period_name, tp.is_locked as period_locked
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN time_periods tp ON p.period_id = tp.id";
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    if ($period_tracking_enabled) {
        $sql .= " ORDER BY COALESCE(p.period_id, 0) DESC, p.name ASC";
    } else {
        $sql .= " ORDER BY p.name ASC";
    }
    
    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $products_result = $stmt->get_result();
    $products = $products_result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate statistics
    $total_products = count($products);
    
    foreach ($products as $product) {
        $product['stock_quantity'] = (int)$product['stock_quantity'];
        $product['min_stock'] = (int)$product['min_stock'];
        $product['cost_price'] = (float)$product['cost_price'];
        
        if ($product['stock_quantity'] == 0) {
            $out_of_stock_count++;
        } elseif ($product['stock_quantity'] <= $product['min_stock']) {
            $low_stock_count++;
        }
        
        $total_stock_value += $product['stock_quantity'] * $product['cost_price'];
        
        // Group by period if available
        if ($period_tracking_enabled) {
            $period_key = $product['period_name'] ?: 'No Period';
            if (!isset($products_by_period[$period_key])) {
                $products_by_period[$period_key] = 0;
            }
            $products_by_period[$period_key]++;
        }
    }
    
} catch (Exception $e) {
    error_log("Products fetch error: " . $e->getMessage());
    $error = "Failed to load products. Please try again.";
}

// If no period tracking, create a dummy entry
if (!$period_tracking_enabled) {
    $products_by_period['All Products'] = $total_products;
}

// Generate year options
$current_year = date('Y');
$years = [$current_year, $current_year + 1];

// Month names with HTML escaping
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

foreach ($months as $key => $value) {
    $months[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Set CSP header - FIXED VERSION
$csp = "default-src 'self'; " .
       "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
       "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
       "img-src 'self' data:; " .
       "font-src 'self' https://cdnjs.cloudflare.com; " .
       "connect-src 'self'; " .
       "frame-src 'none'; " .
       "object-src 'none'; " .
       "base-uri 'self'; " .
       "form-action 'self'";

header("Content-Security-Policy: " . $csp);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Products Management - Vinmel Irrigation Business System">
    <meta name="robots" content="noindex, nofollow">
    <title>Products Management - Vinmel Irrigation</title>
    
    <!-- Removed redundant CSP meta tag - using HTTP header instead -->
    
    <!-- Use integrity hashes for CDN resources -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" 
          integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" 
          crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" 
          integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" 
          crossorigin="anonymous">
    <link href="style.css" rel="stylesheet">
    <style>
        .product-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 0.9rem;
        }
        .card-primary { border-left: 4px solid #007bff; }
        .card-success { border-left: 4px solid #28a745; }
        .card-warning { border-left: 4px solid #ffc107; }
        .card-info { border-left: 4px solid #17a2b8; }
        .card-danger { border-left: 4px solid #dc3545; }
        .icon-bg {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon-bg-primary { background-color: rgba(0, 123, 255, 0.1); color: #007bff; }
        .icon-bg-success { background-color: rgba(40, 167, 69, 0.1); color: #28a745; }
        .icon-bg-warning { background-color: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .icon-bg-danger { background-color: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .period-filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .sku-badge {
            background-color: #e9ecef;
            color: #495057;
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
        }
        .security-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            background: #28a745;
            color: white;
            border-radius: 3px;
            margin-left: 5px;
        }
        .audit-log {
            font-size: 0.8rem;
            color: #6c757d;
            background-color: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            margin-top: 5px;
        }
        .stock-alert-card {
            background: linear-gradient(135deg, #ff9966 0%, #ff5e62 100%);
            color: white;
            border: none;
        }
        .update-stock-form {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #28a745;
        }
        .modal-backdrop {
            z-index: 1040;
        }
        .modal {
            z-index: 1050;
        }
        .debug-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
    </style>
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
                            <i class="fas fa-boxes me-2"></i>Products Management
                           
                        </h1>
                        <p class="lead mb-0">Global product catalog </p>
                      
                    </div>
                    <div class="text-end">
                        <?php if ($period_tracking_enabled && $current_period): ?>
                            <div class="badge bg-primary fs-6 p-2">
                                <i class="fas fa-calendar me-1"></i>
                                Adding to: <?= htmlspecialchars($current_period['period_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php elseif ($period_tracking_enabled): ?>
                            <div class="badge bg-warning fs-6 p-2">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                No Active Period
                            </div>
                        <?php endif; ?>
                        <div class="btn-group ms-2">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                                <i class="fas fa-plus me-2"></i>Add/Update Product
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                                <i class="fas fa-tags me-2"></i>Manage Categories
                            </button>
                            <button type="button" class="btn btn-warning" onclick="testCSRF()">
                                <i class="fas fa-bug me-2"></i>Test CSRF
                            </button>
                        </div>
                    </div>
                </div>

              
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

                <!-- Category Alerts -->
                <?php if ($category_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($category_message, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($category_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($category_error, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

              

                <!-- Low Stock Alert Card -->
                <?php if ($low_stock_count > 0 || $out_of_stock_count > 0): ?>
                <div class="card stock-alert-card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="card-title text-white mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Stock Alerts
                                </h5>
                                <p class="text-white mb-0">
                                    <?php if ($out_of_stock_count > 0): ?>
                                        <i class="fas fa-times-circle me-1"></i><?= $out_of_stock_count ?> product(s) out of stock
                                        <?php if ($low_stock_count > 0): ?>
                                            and <?= $low_stock_count ?> product(s) low on stock.
                                        <?php else: ?>
                                            .
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <i class="fas fa-exclamation-circle me-1"></i><?= $low_stock_count ?> product(s) low on stock.
                                    <?php endif; ?>
                                    <br><small>Click on product names below to view and update stock.</small>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <a href="#low-stock-section" class="btn btn-light">
                                    <i class="fas fa-arrow-down me-1"></i>View Alerts
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Period Filter Card -->
                <?php if ($period_tracking_enabled && !empty($periods)): ?>
                <div class="card period-filter-card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h5 class="card-title text-white mb-0">
                                    <i class="fas fa-filter me-2"></i>Filter by Period
                                </h5>
                                <p class="text-white-50 mb-0">View products from specific time periods</p>
                            </div>
                            <div class="col-md-6">
                                <form method="GET" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                    <select name="period_id" class="form-select" onchange="this.form.submit()">
                                        <option value="">All Periods</option>
                                        <?php foreach ($periods as $id => $period): ?>
                                            <option value="<?= $id ?>" 
                                                <?= $selected_period_id == $id ? 'selected' : '' ?>>
                                                <?= $period['period_name'] ?>
                                                <?= $period['is_locked'] ? ' (Locked)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($selected_period_id): ?>
                                        <a href="?" class="btn btn-light">
                                            <i class="fas fa-times me-1"></i>Clear
                                        </a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card card-primary h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Total Products</h5>
                                        <h2 class="display-6 fw-bold"><?= $total_products ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-primary">
                                        <i class="fas fa-boxes fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">
                                    <?php if ($selected_period_id && isset($periods[$selected_period_id])): ?>
                                        In <?= $periods[$selected_period_id]['period_name'] ?>
                                    <?php else: ?>
                                        All active products
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-success h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Stock Value</h5>
                                        <h2 class="display-6 fw-bold">KSH <?= number_format($total_stock_value, 2) ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-success">
                                        <i class="fas fa-coins fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Total inventory value</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-warning h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Low Stock</h5>
                                        <h2 class="display-6 fw-bold"><?= $low_stock_count ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-warning">
                                        <i class="fas fa-exclamation-triangle fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Need restocking</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-danger h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Out of Stock</h5>
                                        <h2 class="display-6 fw-bold"><?= $out_of_stock_count ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-danger">
                                        <i class="fas fa-times-circle fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Urgent restocking needed</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stock Update Form (for low stock items) -->
                <?php 
                // Find first low stock item for quick update
                $first_low_stock = null;
                foreach ($products as $product) {
                    if ($product['stock_quantity'] <= $product['min_stock'] && $product['stock_quantity'] > 0) {
                        $first_low_stock = $product;
                        break;
                    }
                }
                
                if ($first_low_stock && !$selected_period_id): ?>
                <div class="update-stock-form mb-4">
                    <h5 class="mb-3">
                        <i class="fas fa-sync-alt me-2 text-success"></i>Quick Stock Update
                    </h5>
                    <form method="POST" class="row g-3 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="product_id" value="<?= $first_low_stock['id'] ?>">
                        
                        <div class="col-md-4">
                            <label class="form-label">Product</label>
                            <div class="form-control bg-light">
                                <strong><?= htmlspecialchars($first_low_stock['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <br>
                                <small class="text-muted">Current stock: <?= $first_low_stock['stock_quantity'] ?> (Min: <?= $first_low_stock['min_stock'] ?>)</small>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="add_quantity" class="form-label">Quantity to Add</label>
                            <input type="number" class="form-control" id="add_quantity" name="add_quantity" 
                                   min="1" max="9999" value="10" required>
                            <div class="form-text">Units to add to stock</div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">New Total</label>
                            <div class="form-control bg-light">
                                <span id="newTotal"><?= $first_low_stock['stock_quantity'] + 10 ?></span> units
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <button type="submit" name="update_stock" class="btn btn-success w-100">
                                <i class="fas fa-plus-circle me-1"></i>Update Stock
                            </button>
                        </div>
                    </form>
                    <div class="mt-2 small text-muted">
                        <i class="fas fa-info-circle me-1"></i>This will record an inventory transaction and update stock levels.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Products Table Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>
                                    <?php if ($selected_period_id && isset($periods[$selected_period_id])): ?>
                                        Products in <?= $periods[$selected_period_id]['period_name'] ?>
                                    <?php elseif ($period_tracking_enabled): ?>
                                        All Products (Filtered by Period)
                                    <?php else: ?>
                                        All Products
                                    <?php endif; ?>
                                </h5>
                                <div class="d-flex gap-2">
                                    <input type="text" id="searchProducts" class="form-control form-control-sm" 
                                           placeholder="Search products..." style="max-width: 200px;"
                                           aria-label="Search products">
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($products)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="products-table">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Product</th>
                                                    <th>SKU</th>
                                                    <?php if ($period_tracking_enabled): ?>
                                                        <th>Period</th>
                                                    <?php endif; ?>
                                                    <th>Category</th>
                                                    <th>Cost Price</th>
                                                    <th>Selling Price</th>
                                                    <th>Stock</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $product): 
                                                    $is_out_of_stock = $product['stock_quantity'] == 0;
                                                    $is_low_stock = !$is_out_of_stock && $product['stock_quantity'] <= $product['min_stock'];
                                                    $is_locked = $period_tracking_enabled && ($product['period_locked'] ?? 0);
                                                    $profit_margin = $product['selling_price'] - $product['cost_price'];
                                                    $margin_percentage = $product['cost_price'] > 0 ? ($profit_margin / $product['cost_price']) * 100 : 0;
                                                    $category_name = $categories[$product['category_id']] ?? 'Unknown Category';
                                                ?>
                                                    <tr class="<?= $is_out_of_stock ? 'table-danger' : ($is_low_stock ? 'table-warning' : '') ?> <?= $is_locked ? 'table-secondary' : '' ?>">
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="product-icon bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3">
                                                                    <i class="fas fa-box"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                                    <?php if ($is_locked): ?>
                                                                        <br><small class="text-danger"><i class="fas fa-lock me-1"></i>Period Locked</small>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($product['description'])): ?>
                                                                        <br><small class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 50), ENT_QUOTES, 'UTF-8') ?>...</small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="sku-badge badge"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        </td>
                                                        <?php if ($period_tracking_enabled): ?>
                                                            <td>
                                                                <?php if ($product['period_name']): ?>
                                                                    <span class="badge bg-light text-dark">
                                                                        <i class="fas fa-calendar me-1"></i>
                                                                        <?= htmlspecialchars($product['period_name'], ENT_QUOTES, 'UTF-8') ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">No Period</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <span class="badge bg-info"><?= htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8') ?></span>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['cost_price'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <strong class="text-success">KSH <?= number_format($product['selling_price'], 2) ?></strong>
                                                            <br><small class="text-muted"><?= number_format($margin_percentage, 1) ?>% margin</small>
                                                        </td>
                                                        <td>
                                                            <div class="progress mb-1" style="height: 8px;">
                                                                <?php 
                                                                $max_stock = max($product['stock_quantity'], $product['min_stock'] * 2);
                                                                $progress = $max_stock > 0 ? ($product['stock_quantity'] / $max_stock) * 100 : 0;
                                                                $progress_class = $is_out_of_stock ? 'bg-danger' : ($is_low_stock ? 'bg-warning' : ($progress < 50 ? 'bg-info' : 'bg-success'));
                                                                ?>
                                                                <div class="progress-bar <?= $progress_class ?>" style="width: <?= $progress ?>%"></div>
                                                            </div>
                                                            <div class="d-flex justify-content-between">
                                                                <span class="<?= $is_out_of_stock ? 'text-danger fw-bold' : ($is_low_stock ? 'text-warning fw-bold' : '') ?>">
                                                                    <?= $product['stock_quantity'] ?>
                                                                </span>
                                                                <small class="text-muted">min: <?= $product['min_stock'] ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if ($is_locked): ?>
                                                                <span class="badge bg-secondary">
                                                                    <i class="fas fa-lock me-1"></i>Locked
                                                                </span>
                                                            <?php elseif ($is_out_of_stock): ?>
                                                                <span class="badge bg-danger">
                                                                    <i class="fas fa-times-circle me-1"></i>Out of Stock
                                                                </span>
                                                            <?php elseif ($is_low_stock): ?>
                                                                <span class="badge bg-warning">
                                                                    <i class="fas fa-exclamation-triangle me-1"></i>Low Stock
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">In Stock</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <?php if (!$is_locked): ?>
                                                                    <!-- Edit Button -->
                                                                    <a href="?edit=<?= $product['id'] ?>" class="btn btn-outline-primary">
                                                                        <i class="fas fa-edit"></i>
                                                                    </a>
                                                                    
                                                                    <!-- Quick Stock Update Button for Low/Out of Stock Items -->
                                                                    <?php if ($is_low_stock || $is_out_of_stock): ?>
                                                                        <button type="button" class="btn btn-outline-success" 
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#stockUpdateModal"
                                                                                data-product-id="<?= $product['id'] ?>"
                                                                                data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                                                data-current-stock="<?= $product['stock_quantity'] ?>"
                                                                                data-min-stock="<?= $product['min_stock'] ?>">
                                                                            <i class="fas fa-plus-circle"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                    
                                                                    <!-- Delete Button -->
                                                                    <form method="POST" class="d-inline" onsubmit="return confirmSecurityDelete()">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                                        <button type="submit" name="delete_product" class="btn btn-outline-danger">
                                                                            <i class="fas fa-trash"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="btn btn-outline-secondary disabled" title="Period is locked">
                                                                        <i class="fas fa-lock"></i>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                                        <h4 class="text-muted">No Products Found</h4>
                                        <p class="text-muted">
                                            <?php if ($selected_period_id): ?>
                                                No products found for the selected period.
                                            <?php else: ?>
                                                No products have been added yet.
                                            <?php endif; ?>
                                        </p>
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                                            <i class="fas fa-plus me-2"></i>Add Your First Product
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Update Modal -->
    <div class="modal fade" id="stockUpdateModal" tabindex="-1" aria-labelledby="stockUpdateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="stockUpdateModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Update Stock
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="stockUpdateForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="product_id" id="modalProductId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Product</label>
                            <div class="form-control bg-light" id="modalProductName"></div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Current Stock</label>
                                <div class="form-control bg-light" id="modalCurrentStock"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Minimum Stock</label>
                                <div class="form-control bg-light" id="modalMinStock"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="modalAddQuantity" class="form-label">Quantity to Add</label>
                            <input type="number" class="form-control" id="modalAddQuantity" name="add_quantity" 
                                   min="1" max="9999" value="10" required>
                            <div class="form-text">Units to add to stock</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Total Stock</label>
                            <div class="form-control bg-light fw-bold" id="modalNewTotal"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_stock" class="btn btn-success">
                            <i class="fas fa-check-circle me-2"></i>Update Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add/Edit Product Modal -->
    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="productModalLabel">
                        <i class="fas <?= $edit_mode ? 'fa-edit' : 'fa-plus' ?> me-2"></i>
                        <?= $edit_mode ? 'Edit Product' : 'Add/Update Product' ?>
                        <span class="badge bg-light text-primary ms-2">DEBUG MODE</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="productForm" autocomplete="off" onsubmit="return true;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="product_id" value="<?= $edit_product['id'] ?>">
                        <input type="hidden" name="update_product" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_product" value="1">
                    <?php endif; ?>
                    
                    <div class="modal-body">
                        <!-- Debug Notice -->
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-bug me-2"></i>
                            <strong>Debug Mode:</strong> All form submissions are logged. Check error logs for details.
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-box me-1"></i>Product Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?= isset($edit_product) ? htmlspecialchars($edit_product['name'], ENT_QUOTES, 'UTF-8') : (isset($_POST['name']) && !isset($_POST['add_category']) ? htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8') : '') ?>" 
                                           required maxlength="255"
                                           pattern="[A-Za-z0-9\s\-&.,]{1,255}"
                                           title="Product name can contain letters, numbers, spaces, hyphens, ampersands, and periods">
                                    <div class="form-text">Max 255 characters. Only alphanumeric and common symbols allowed.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sku" class="form-label">
                                        <i class="fas fa-barcode me-1"></i>SKU
                                        <?php if ($edit_mode): ?>
                                            <span class="text-danger">*</span>
                                        <?php endif; ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="sku" name="sku" 
                                               value="<?= isset($edit_product) ? htmlspecialchars($edit_product['sku'], ENT_QUOTES, 'UTF-8') : (isset($_POST['sku']) && !isset($_POST['add_category']) ? htmlspecialchars($_POST['sku'], ENT_QUOTES, 'UTF-8') : '') ?>" 
                                               <?= $edit_mode ? 'required' : '' ?> 
                                               maxlength="100"
                                               pattern="[A-Za-z0-9\-]{1,100}"
                                               title="SKU can contain letters, numbers, and hyphens">
                                        <?php if (!$edit_mode): ?>
                                            <button type="button" class="btn btn-outline-secondary" id="generateSKU">
                                                <i class="fas fa-magic me-1"></i>Auto Generate
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-text">
                                        <?php if ($edit_mode): ?>
                                            Unique product identifier (required for updates)
                                        <?php else: ?>
                                            Leave empty for auto-generation or enter custom SKU (optional for new products)
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="category_id" class="form-label">
                                        <i class="fas fa-tag me-1"></i>Category <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <select class="form-select" id="category_id" name="category_id" required>
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $id => $name): ?>
                                                <option value="<?= $id ?>" 
                                                    <?= (isset($edit_product) && $edit_product['category_id'] == $id) ? 'selected' : '' ?>
                                                    <?= (isset($_POST['category_id']) && $_POST['category_id'] == $id && !isset($_POST['add_category'])) ? 'selected' : '' ?>>
                                                    <?= $name ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary" onclick="openCategoryModal()">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier" class="form-label">
                                        <i class="fas fa-truck me-1"></i>Supplier
                                    </label>
                                    <input type="text" class="form-control" id="supplier" name="supplier" 
                                           value="<?= isset($edit_product) ? htmlspecialchars($edit_product['supplier'], ENT_QUOTES, 'UTF-8') : (isset($_POST['supplier']) && !isset($_POST['add_category']) ? htmlspecialchars($_POST['supplier'], ENT_QUOTES, 'UTF-8') : '') ?>"
                                           maxlength="255">
                                    <div class="form-text">Optional supplier information</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="cost_price" class="form-label">
                                        <i class="fas fa-money-bill-wave me-1"></i>Cost Price (KSH) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" step="0.01" min="0" max="9999999.99" class="form-control" id="cost_price" name="cost_price" 
                                           value="<?= isset($edit_product) ? $edit_product['cost_price'] : (isset($_POST['cost_price']) && !isset($_POST['add_category']) ? $_POST['cost_price'] : '0.00') ?>" 
                                           required>
                                    <div class="form-text">Maximum: 9,999,999.99</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="selling_price" class="form-label">
                                        <i class="fas fa-tags me-1"></i>Selling Price (KSH) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" step="0.01" min="0" max="9999999.99" class="form-control" id="selling_price" name="selling_price" 
                                           value="<?= isset($edit_product) ? $edit_product['selling_price'] : (isset($_POST['selling_price']) && !isset($_POST['add_category']) ? $_POST['selling_price'] : '0.00') ?>" 
                                           required>
                                    <div class="form-text">Must be ≥ Cost Price</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="stock_quantity" class="form-label">
                                        <i class="fas fa-boxes me-1"></i>Stock Quantity <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" min="0" max="999999" class="form-control" id="stock_quantity" name="stock_quantity" 
                                           value="<?= isset($edit_product) ? $edit_product['stock_quantity'] : (isset($_POST['stock_quantity']) && !isset($_POST['add_category']) ? $_POST['stock_quantity'] : '0') ?>" 
                                           required>
                                    <div class="form-text">
                                        <?php if ($edit_mode): ?>
                                            Changing stock quantity will record an inventory transaction
                                        <?php else: ?>
                                            If product exists, this quantity will be added to current stock and recorded in inventory
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="min_stock" class="form-label">
                                        <i class="fas fa-exclamation-triangle me-1"></i>Minimum Stock Level
                                    </label>
                                    <input type="number" min="0" max="999999" class="form-control" id="min_stock" name="min_stock" 
                                           value="<?= isset($edit_product) ? $edit_product['min_stock'] : (isset($_POST['min_stock']) && !isset($_POST['add_category']) ? $_POST['min_stock'] : '5') ?>">
                                    <div class="form-text">Alert when stock falls below this level</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">
                                <i class="fas fa-align-left me-1"></i>Description
                            </label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      maxlength="1000"><?= isset($edit_product) ? htmlspecialchars($edit_product['description'], ENT_QUOTES, 'UTF-8') : (isset($_POST['description']) && !isset($_POST['add_category']) ? htmlspecialchars($_POST['description'], ENT_QUOTES, 'UTF-8') : '') ?></textarea>
                            <div class="form-text">Max 1000 characters</div>
                        </div>

                        <?php if ($period_tracking_enabled && !$current_period && !$edit_mode): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No active time period! Products added will not be assigned to any period.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <?php if ($edit_mode): ?>
                            <a href="?cancel_edit" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Update Product
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus me-2"></i>
                                Add/Update Product
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manage Categories Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="categoryModalLabel">
                        <i class="fas fa-tags me-2"></i>Manage Categories
                        <span class="badge bg-light text-primary ms-2">SECURE</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Security Notice -->
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-shield-alt me-2"></i>
                        Category management is secured with CSRF protection.
                    </div>
                    
                    <!-- Add Category Form -->
                    <div class="mb-4">
                        <h6 class="border-bottom pb-2">Add New Category</h6>
                        <form method="POST" id="addCategoryForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="add_category" value="1">
                            <div class="mb-3">
                                <label for="category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="category_name" name="category_name" 
                                       value="<?= isset($_POST['category_name']) ? htmlspecialchars($_POST['category_name'], ENT_QUOTES, 'UTF-8') : '' ?>" 
                                       required maxlength="100"
                                       pattern="[A-Za-z0-9\s\-&.,]{1,100}"
                                       title="Category name can contain letters, numbers, spaces, hyphens, ampersands, and periods">
                                <div class="form-text">Max 100 characters</div>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Add Category
                            </button>
                        </form>
                    </div>

                    <!-- Existing Categories -->
                    <div>
                        <h6 class="border-bottom pb-2">Existing Categories</h6>
                        <?php if (!empty($categories)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Products</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categories as $id => $name): 
                                            // Count products in this category
                                            $count_sql = "SELECT COUNT(*) as product_count FROM products WHERE category_id = ? AND is_active = 1";
                                            $count_stmt = $db->prepare($count_sql);
                                            $count_stmt->bind_param("i", $id);
                                            $count_stmt->execute();
                                            $count_result = $count_stmt->get_result();
                                            $product_count = $count_result->fetch_assoc()['product_count'];
                                        ?>
                                            <tr>
                                                <td><?= $name ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?= $product_count ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($product_count == 0): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirmSecurityCategoryDelete()">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="category_id" value="<?= $id ?>">
                                                            <input type="hidden" name="delete_category" value="1">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">In use</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No categories found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap with integrity hash to prevent CSP issues -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" 
            integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" 
            crossorigin="anonymous"></script>
    <script>
        'use strict';
        
        // Debug function to test CSRF
        function testCSRF() {
            const token = '<?= $csrf_token ?>';
            alert('CSRF Token (first 30 chars):\n' + token.substring(0, 30) + '...\n\nFull token is logged in console.');
            console.log('CSRF Token:', token);
        }
        
        // Security confirmation functions
        function confirmSecurityDelete() {
            return confirm('⚠️ SECURITY DELETE CONFIRMATION\n\nAre you sure you want to deactivate this product?\n\nThis will:\n• Remove product from active listings\n• Preserve product data for audit trails\n• Record inventory removal transaction\n• Log this action for security compliance\n\nThis action is logged and cannot be undone without administrator assistance.');
        }
        
        function confirmSecurityCategoryDelete() {
            return confirm('⚠️ SECURITY CATEGORY DELETE CONFIRMATION\n\nAre you sure you want to delete this category?\n\n⚠️ WARNING: This action cannot be undone!\n\nThis category is not in use by any products.\n\nThis action is permanently logged for audit compliance.');
        }
        
        // Function to open category modal
        function openCategoryModal() {
            const productModal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
            if (productModal) {
                productModal.hide();
            }
            
            setTimeout(() => {
                const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
                categoryModal.show();
            }, 300);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DEBUG: Page loaded');
            console.log('CSRF Token (first 10 chars):', '<?= substr($csrf_token, 0, 10) ?>...');
            
            // Only show product modal if we're in edit mode or have product errors
            // AND we don't have category messages/errors
            <?php if (($edit_mode || $error) && empty($category_message) && empty($category_error)): ?>
                console.log('DEBUG: Showing product modal for edit');
                setTimeout(() => {
                    const productModal = new bootstrap.Modal(document.getElementById('productModal'));
                    productModal.show();
                }, 300);
            <?php endif; ?>
            
            // Show category modal if we have category messages/errors
            <?php if ($category_message || $category_error): ?>
                console.log('DEBUG: Showing category modal');
                setTimeout(() => {
                    const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
                    categoryModal.show();
                }, 300);
            <?php endif; ?>
            
            // Auto-hide alerts after 7 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.classList.contains('show')) {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                        bsAlert.close();
                    }
                }, 7000);
            });
            
            // Auto-generate SKU functionality
            const generateSKUbtn = document.getElementById('generateSKU');
            if (generateSKUbtn) {
                generateSKUbtn.addEventListener('click', function() {
                    const name = document.getElementById('name').value.trim();
                    const categoryId = document.getElementById('category_id').value;
                    
                    if (!name || !categoryId) {
                        alert('⚠️ Security Validation Failed\n\nPlease enter product name and select category first.');
                        return;
                    }
                    
                    // Validate name (basic check)
                    if (!/^[A-Za-z0-9\s\-&.,]{1,255}$/.test(name)) {
                        alert('⚠️ Invalid Product Name\n\nName contains invalid characters.');
                        return;
                    }
                    
                    // Generate SKU based on name and category
                    const nameAbbr = name.split(' ')
                        .map(word => word.charAt(0).toUpperCase())
                        .join('')
                        .substring(0, 3);
                    const timestamp = Date.now().toString().slice(-4);
                    const sku = nameAbbr + '-' + timestamp;
                    
                    document.getElementById('sku').value = sku;
                    console.log('DEBUG: Generated SKU:', sku);
                });
            }
            
            // Product search functionality
            const searchInput = document.getElementById('searchProducts');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const rows = document.querySelectorAll('#products-table tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }
            
            // Price validation
            const costPriceInput = document.getElementById('cost_price');
            const sellingPriceInput = document.getElementById('selling_price');
            
            if (costPriceInput && sellingPriceInput) {
                costPriceInput.addEventListener('change', validatePrices);
                sellingPriceInput.addEventListener('change', validatePrices);
            }
            
            function validatePrices() {
                const costPrice = parseFloat(costPriceInput?.value) || 0;
                const sellingPrice = parseFloat(sellingPriceInput?.value) || 0;
                
                if (sellingPrice < costPrice) {
                    sellingPriceInput.setCustomValidity('Selling price cannot be less than cost price');
                    sellingPriceInput.classList.add('is-invalid');
                } else {
                    sellingPriceInput.setCustomValidity('');
                    sellingPriceInput.classList.remove('is-invalid');
                }
            }
            
            // Form submission - SIMPLIFIED for debugging
            const productForm = document.getElementById('productForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (productForm) {
                console.log('DEBUG: Form found, adding simplified submit handler');
                
                // Remove any complex validation temporarily
                productForm.addEventListener('submit', function(e) {
                    console.log('DEBUG: Form submitted');
                    
                    // Simple validation only
                    const requiredFields = productForm.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        } else {
                            field.classList.remove('is-invalid');
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('⚠️ Please fill in all required fields.');
                        return false;
                    }
                    
                    // Disable button and show loading state
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                    }
                    
                    // Allow form to submit normally
                    return true;
                });
            }
            
            // Input sanitization on blur
            const textInputs = document.querySelectorAll('input[type="text"], textarea');
            textInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    // Remove any script tags or suspicious content
                    this.value = this.value.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
                    this.value = this.value.replace(/javascript:/gi, '');
                    this.value = this.value.replace(/on\w+=/gi, '');
                });
            });
            
            // Quick stock update form calculation
            const addQuantityInput = document.getElementById('add_quantity');
            const newTotalSpan = document.getElementById('newTotal');
            
            if (addQuantityInput && newTotalSpan) {
                addQuantityInput.addEventListener('input', function() {
                    const currentStock = <?= $first_low_stock['stock_quantity'] ?? 0 ?>;
                    const addQuantity = parseInt(this.value) || 0;
                    newTotalSpan.textContent = currentStock + addQuantity;
                });
            }
            
            // Stock update modal functionality
            const stockUpdateModal = document.getElementById('stockUpdateModal');
            if (stockUpdateModal) {
                stockUpdateModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const productId = button.getAttribute('data-product-id');
                    const productName = button.getAttribute('data-product-name');
                    const currentStock = button.getAttribute('data-current-stock');
                    const minStock = button.getAttribute('data-min-stock');
                    
                    document.getElementById('modalProductId').value = productId;
                    document.getElementById('modalProductName').textContent = productName;
                    document.getElementById('modalCurrentStock').textContent = currentStock + ' units';
                    document.getElementById('modalMinStock').textContent = minStock + ' units';
                    
                    // Calculate initial new total
                    const modalAddQuantity = document.getElementById('modalAddQuantity');
                    const modalNewTotal = document.getElementById('modalNewTotal');
                    modalNewTotal.textContent = (parseInt(currentStock) + parseInt(modalAddQuantity.value)) + ' units';
                    
                    // Update on quantity change
                    modalAddQuantity.addEventListener('input', function() {
                        const addQty = parseInt(this.value) || 0;
                        modalNewTotal.textContent = (parseInt(currentStock) + addQty) + ' units';
                    });
                });
            }
            
            // Security warning in console
            if (typeof console !== "undefined") {
                console.log("%c🔐 DEBUG MODE ACTIVE", "color: red; font-size: 18px; font-weight: bold;");
                console.log("%cAll form submissions are logged to error logs.", "color: orange; font-size: 14px;");
            }
        });
    </script>
</body>
</html>