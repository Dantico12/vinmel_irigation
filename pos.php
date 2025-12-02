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

function isSeller() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

if (!isLoggedIn() || !isSeller()) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';
$receipt_number = '';
$last_sale_amount = 0;
$receipt_id = 0; // Add this variable to store receipt ID

// Company details for receipts
$company_details = [
    'name' => 'Vinmel Irrigation',
    'address' => 'Nairobi, Kenya',
    'phone' => '+254 700 000000',
    'email' => 'info@vinmel.com'
];

// Get current period for tracking
$current_period = getCurrentTimePeriod($user_id, $db);
$period_check = canModifyData($user_id, $db);

/* -------------------------------------------------------
   RECEIPT STORAGE FUNCTIONS
-------------------------------------------------------- */

/**
 * Generate receipt HTML for storage
 */
function generateReceiptHTML($receipt_number, $transaction, $items, $company_details) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; }
            .receipt { width: 300px; margin: 0 auto; }
            .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
            .header h2 { margin: 5px 0; }
            .info { margin: 10px 0; }
            .info-row { display: flex; justify-content: space-between; margin: 3px 0; }
            .items-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            .items-table th, .items-table td { border-bottom: 1px solid #ddd; padding: 5px; text-align: left; }
            .items-table th { border-bottom: 2px solid #000; }
            .total-section { margin-top: 10px; }
            .total-row { display: flex; justify-content: space-between; font-weight: bold; padding: 3px 0; }
            .footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 2px dashed #000; font-size: 10px; }
        </style>
    </head>
    <body>
        <div class="receipt">
            <div class="header">
                <h2><?= htmlspecialchars($company_details['name']) ?></h2>
                <p><?= htmlspecialchars($company_details['address']) ?></p>
                <p>Tel: <?= htmlspecialchars($company_details['phone']) ?></p>
                <p>Email: <?= htmlspecialchars($company_details['email']) ?></p>
            </div>
            
            <div class="info">
                <div class="info-row">
                    <span>Receipt #:</span>
                    <span><?= htmlspecialchars($receipt_number) ?></span>
                </div>
                <div class="info-row">
                    <span>Date:</span>
                    <span><?= date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])) ?></span>
                </div>
                <?php if ($transaction['customer_name']): ?>
                <div class="info-row">
                    <span>Customer:</span>
                    <span><?= htmlspecialchars($transaction['customer_name']) ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span>Seller:</span>
                    <span><?= htmlspecialchars($transaction['seller_name']) ?></span>
                </div>
                <div class="info-row">
                    <span>Payment:</span>
                    <span><?= strtoupper(htmlspecialchars($transaction['payment_method'])) ?></span>
                </div>
            </div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?><br><small><?= htmlspecialchars($item['sku']) ?></small></td>
                        <td><?= $item['quantity'] ?></td>
                        <td>KSh <?= number_format($item['unit_price'], 2) ?></td>
                        <td>KSh <?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="total-section">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>KSh <?= number_format($transaction['total_amount'], 2) ?></span>
                </div>
                <?php if ($transaction['discount_amount'] > 0): ?>
                <div class="total-row">
                    <span>Discount:</span>
                    <span>- KSh <?= number_format($transaction['discount_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($transaction['tax_amount'] > 0): ?>
                <div class="total-row">
                    <span>Tax (16%):</span>
                    <span>KSh <?= number_format($transaction['tax_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row" style="font-size: 14px; border-top: 2px solid #000; padding-top: 5px;">
                    <span>TOTAL:</span>
                    <span>KSh <?= number_format($transaction['net_amount'], 2) ?></span>
                </div>
            </div>
            
            <div class="footer">
                <p>Thank you for your business!</p>
                <p><?= htmlspecialchars($company_details['name']) ?></p>
                <p>Date: <?= date('Y-m-d H:i:s') ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Save receipt details after successful sale
 */
function saveReceipt($transaction_id, $receipt_number, $user_id, $db) {
    try {
        // Get transaction details
        $transaction_sql = "SELECT t.*, u.name as seller_name, c.name as customer_name, 
                                   c.phone as customer_phone, c.email as customer_email,
                                   tp.id as period_id
                          FROM transactions t
                          LEFT JOIN users u ON t.user_id = u.id
                          LEFT JOIN customers c ON t.customer_id = c.id
                          LEFT JOIN time_periods tp ON t.time_period_id = tp.id
                          WHERE t.id = ?";
        $stmt = $db->prepare($transaction_sql);
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();
        
        if (!$transaction) {
            throw new Exception("Transaction not found");
        }
        
        // Get transaction items
        $items_sql = "SELECT ti.*, p.name as product_name, p.sku 
                     FROM transaction_items ti
                     JOIN products p ON ti.product_id = p.id
                     WHERE ti.transaction_id = ?
                     ORDER BY ti.id";
        $items_stmt = $db->prepare($items_sql);
        $items_stmt->bind_param("i", $transaction_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = [];
        
        while ($item = $items_result->fetch_assoc()) {
            $items[] = [
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price']
            ];
        }
        
        // Company details
        $company_details = [
            'name' => 'Vinmel Irrigation',
            'address' => 'Nairobi, Kenya',
            'phone' => '+254 700 000000',
            'email' => 'info@vinmel.com'
        ];
        
        // Generate receipt HTML
        $receipt_html = generateReceiptHTML($receipt_number, $transaction, $items, $company_details);
        
        // Save to receipts table (check if table exists first)
        $table_check = "SHOW TABLES LIKE 'receipts'";
        $table_exists = $db->query($table_check)->num_rows > 0;
        
        if (!$table_exists) {
            // Create receipts table if it doesn't exist
            $create_table = "CREATE TABLE IF NOT EXISTS `receipts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `transaction_id` int(11) NOT NULL,
                `receipt_number` varchar(50) NOT NULL,
                `customer_name` varchar(255) DEFAULT NULL,
                `customer_phone` varchar(20) DEFAULT NULL,
                `customer_email` varchar(100) DEFAULT NULL,
                `seller_id` int(11) NOT NULL,
                `seller_name` varchar(100) NOT NULL,
                `total_amount` decimal(10,2) NOT NULL,
                `tax_amount` decimal(10,2) DEFAULT 0.00,
                `discount_amount` decimal(10,2) DEFAULT 0.00,
                `net_amount` decimal(10,2) NOT NULL,
                `payment_method` enum('cash','card','mobile','bank') DEFAULT 'cash',
                `transaction_date` datetime NOT NULL,
                `items_json` text NOT NULL COMMENT 'JSON array of items purchased',
                `receipt_html` text NOT NULL COMMENT 'HTML format of receipt for printing/viewing',
                `period_id` int(11) DEFAULT NULL,
                `company_details` text DEFAULT NULL COMMENT 'JSON of company details at time of sale',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `receipt_number` (`receipt_number`),
                KEY `transaction_id` (`transaction_id`),
                KEY `seller_id` (`seller_id`),
                KEY `period_id` (`period_id`),
                KEY `transaction_date` (`transaction_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if (!$db->query($create_table)) {
                throw new Exception("Failed to create receipts table: " . $db->error);
            }
        }
        
        // Insert receipt
        $insert_sql = "INSERT INTO receipts (
            transaction_id, receipt_number, customer_name, customer_phone, customer_email,
            seller_id, seller_name, total_amount, tax_amount, discount_amount, net_amount,
            payment_method, transaction_date, items_json, receipt_html, period_id, company_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $db->prepare($insert_sql);
        $items_json = json_encode($items);
        $company_json = json_encode($company_details);
        $insert_stmt->bind_param(
            "issssiddddsssssis",
            $transaction_id,
            $receipt_number,
            $transaction['customer_name'],
            $transaction['customer_phone'],
            $transaction['customer_email'],
            $user_id,
            $transaction['seller_name'],
            $transaction['total_amount'],
            $transaction['tax_amount'],
            $transaction['discount_amount'],
            $transaction['net_amount'],
            $transaction['payment_method'],
            $transaction['transaction_date'],
            $items_json,
            $receipt_html,
            $transaction['period_id'],
            $company_json
        );
        
        return $insert_stmt->execute();
        
    } catch (Exception $e) {
        error_log("Error saving receipt: " . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------
   CART MANAGEMENT
-------------------------------------------------------- */

// Initialize cart if not exists
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
    $_SESSION['pos_customer'] = null;
    $_SESSION['pos_discount'] = 0;
    $_SESSION['pos_payment_method'] = 'cash';
}

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    
    // Validate quantity
    if ($quantity <= 0) {
        $error = "Quantity must be greater than 0!";
    } else {
        // Get product details - REMOVED created_by filter
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.id = ?"; // Removed: AND p.created_by = ?
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $product_id); // Only product_id
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $product = $result->fetch_assoc();
            
            // Check stock availability
            if ($product['stock_quantity'] < $quantity) {
                $error = "Insufficient stock! Only {$product['stock_quantity']} units available.";
            } else {
                // Add to cart or update quantity
                if (isset($_SESSION['pos_cart'][$product_id])) {
                    $_SESSION['pos_cart'][$product_id]['quantity'] += $quantity;
                } else {
                    $_SESSION['pos_cart'][$product_id] = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'selling_price' => $product['selling_price'],
                        'quantity' => $quantity,
                        'category' => $product['category_name']
                    ];
                }
                $message = "{$product['name']} added to cart!";
            }
        } else {
            $error = "Product not found!";
        }
    }
}

// Handle update cart quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    
    if ($quantity <= 0) {
        // Remove item if quantity is 0
        unset($_SESSION['pos_cart'][$product_id]);
        $message = "Item removed from cart!";
    } else {
        // Update quantity
        if (isset($_SESSION['pos_cart'][$product_id])) {
            $_SESSION['pos_cart'][$product_id]['quantity'] = $quantity;
            $message = "Cart updated!";
        }
    }
}

// Handle remove from cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_from_cart'])) {
    $product_id = intval($_POST['product_id']);
    
    if (isset($_SESSION['pos_cart'][$product_id])) {
        unset($_SESSION['pos_cart'][$product_id]);
        $message = "Item removed from cart!";
    }
}

// Handle customer update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $customer_email = trim($_POST['customer_email']);
    
    $_SESSION['pos_customer'] = [
        'name' => $customer_name,
        'phone' => $customer_phone,
        'email' => $customer_email
    ];
    
    $message = "Customer information updated!";
}

// Handle discount update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_discount'])) {
    $discount = floatval($_POST['discount']);
    
    if ($discount >= 0) {
        $_SESSION['pos_discount'] = $discount;
        $message = "Discount updated!";
    } else {
        $error = "Discount cannot be negative!";
    }
}

// Handle payment method update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_method'])) {
    $_SESSION['pos_payment_method'] = $_POST['payment_method'];
    $message = "Payment method updated!";
}

// Handle complete sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    // Validate cart
    if (empty($_SESSION['pos_cart'])) {
        $error = "Cart is empty! Add products to complete sale.";
    } else {
        // Calculate totals
        $subtotal = 0;
        foreach ($_SESSION['pos_cart'] as $item) {
            $subtotal += $item['selling_price'] * $item['quantity'];
        }
        
        $discount_amount = $_SESSION['pos_discount'];
        $tax_rate = 0.16; // 16% VAT
        $tax_amount = ($subtotal - $discount_amount) * $tax_rate;
        $net_amount = $subtotal - $discount_amount + $tax_amount;
        
        // Generate receipt number
        $receipt_number = 'RCP' . date('Ymd') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        // Store the sale amount for display BEFORE clearing cart
        $last_sale_amount = $net_amount;
        
        // Start transaction
        $db->begin_transaction();
        
        try {
            // First, let's examine the actual structure of the transactions table
            $table_structure_sql = "DESCRIBE transactions";
            $table_result = $db->query($table_structure_sql);
            $table_columns = [];
            while ($row = $table_result->fetch_assoc()) {
                $table_columns[] = $row['Field'];
            }
            
            // Build the SQL query dynamically based on actual table structure
            $columns = [];
            $placeholders = [];
            $bind_types = "";
            $bind_values = [];
            
            // Always include these columns
            $columns[] = 'receipt_number';
            $placeholders[] = '?';
            $bind_types .= "s";
            $bind_values[] = $receipt_number;
            
            // Check if customer_id exists and include it
            if (in_array('customer_id', $table_columns)) {
                $columns[] = 'customer_id';
                $placeholders[] = '?';
                $bind_types .= "i";
                $customer_id = NULL;
                $bind_values[] = $customer_id;
            }
            
            // Include user_id
            $columns[] = 'user_id';
            $placeholders[] = '?';
            $bind_types .= "i";
            $bind_values[] = $user_id;
            
            // Include time_period_id if it exists
            if (in_array('time_period_id', $table_columns)) {
                $columns[] = 'time_period_id';
                $placeholders[] = '?';
                $bind_types .= "i";
                $period_id = $current_period ? $current_period['id'] : NULL;
                $bind_values[] = $period_id;
            }
            
            // Include amount fields
            $amount_fields = ['total_amount', 'tax_amount', 'discount_amount', 'net_amount'];
            foreach ($amount_fields as $field) {
                if (in_array($field, $table_columns)) {
                    $columns[] = $field;
                    $placeholders[] = '?';
                    $bind_types .= "d";
                    // Assign values based on field name
                    switch($field) {
                        case 'total_amount': 
                            $value = $subtotal;
                            break;
                        case 'tax_amount': 
                            $value = $tax_amount;
                            break;
                        case 'discount_amount': 
                            $value = $discount_amount;
                            break;
                        case 'net_amount': 
                            $value = $net_amount;
                            break;
                        default:
                            $value = 0;
                    }
                    $bind_values[] = $value;
                }
            }
            
            // Include payment_method
            if (in_array('payment_method', $table_columns)) {
                $columns[] = 'payment_method';
                $placeholders[] = '?';
                $bind_types .= "s";
                $payment_method = $_SESSION['pos_payment_method'];
                $bind_values[] = $payment_method;
            }
            
            // Include transaction_date (use NOW() for MySQL)
            $columns[] = 'transaction_date';
            $placeholders[] = 'NOW()';
            
            // Build the final SQL
            $transaction_sql = "INSERT INTO transactions (" . implode(', ', $columns) . ") 
                               VALUES (" . implode(', ', $placeholders) . ")";
            
            $transaction_stmt = $db->prepare($transaction_sql);
            
            if (!$transaction_stmt) {
                throw new Exception("Failed to prepare transaction statement: " . $db->error);
            }
            
            // Bind parameters
            $transaction_stmt->bind_param($bind_types, ...$bind_values);
            
            if (!$transaction_stmt->execute()) {
                throw new Exception("Failed to create transaction: " . $db->error);
            }
            
            $transaction_id = $db->insert_id;
            
            // Check if transaction_items table exists, create if not
            $check_items_table = "SHOW TABLES LIKE 'transaction_items'";
            $items_table_exists = $db->query($check_items_table) && $db->query($check_items_table)->num_rows > 0;
            
            if (!$items_table_exists) {
                // Create transaction_items table
                $create_items_table = "CREATE TABLE IF NOT EXISTS transaction_items (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    transaction_id INT NOT NULL,
                    product_id INT NOT NULL,
                    quantity INT NOT NULL,
                    unit_price DECIMAL(10,2) NOT NULL,
                    total_price DECIMAL(10,2) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                )";
                
                if (!$db->query($create_items_table)) {
                    throw new Exception("Failed to create transaction_items table: " . $db->error);
                }
            }
            
            // Insert transaction items and update stock
            foreach ($_SESSION['pos_cart'] as $product_id => $item) {
                // Insert transaction item
                $item_sql = "INSERT INTO transaction_items (
                    transaction_id, product_id, quantity, unit_price, total_price
                ) VALUES (?, ?, ?, ?, ?)";
                
                $item_stmt = $db->prepare($item_sql);
                $total_price = $item['selling_price'] * $item['quantity'];
                
                $item_stmt->bind_param(
                    "iiidd", 
                    $transaction_id, $product_id, $item['quantity'],
                    $item['selling_price'], $total_price
                );
                
                if (!$item_stmt->execute()) {
                    throw new Exception("Failed to add transaction item: " . $db->error);
                }
                
                // Update product stock - REMOVED created_by restriction
                $update_stock_sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
                $update_stmt = $db->prepare($update_stock_sql);
                $update_stmt->bind_param("ii", $item['quantity'], $product_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update product stock: " . $db->error);
                }
            }
            
            // Commit transaction
            $db->commit();
            
            // SAVE RECEIPT TO RECEIPTS TABLE
            $receipt_saved = saveReceipt($transaction_id, $receipt_number, $user_id, $db);
            
            if ($receipt_saved) {
                // Get the receipt ID that was just created
                $receipt_sql = "SELECT id FROM receipts WHERE receipt_number = ?";
                $receipt_stmt = $db->prepare($receipt_sql);
                $receipt_stmt->bind_param("s", $receipt_number);
                $receipt_stmt->execute();
                $receipt_result = $receipt_stmt->get_result();
                $receipt_data = $receipt_result->fetch_assoc();
                
                $receipt_id = $receipt_data['id'] ?? 0;
                
                $message = "Sale completed successfully! Receipt: $receipt_number";
            } else {
                $message = "Sale completed but receipt saving failed! Transaction ID: $transaction_id";
            }
            
            // Clear cart and reset customer for next sale
            $_SESSION['pos_cart'] = [];
            $_SESSION['pos_discount'] = 0;
            $_SESSION['pos_customer'] = null; // Reset customer for next sale
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

// Handle clear cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cart'])) {
    $_SESSION['pos_cart'] = [];
    $_SESSION['pos_discount'] = 0;
    $_SESSION['pos_customer'] = null; // Also clear customer when clearing cart
    $message = "Cart cleared!";
}

/* -------------------------------------------------------
   FETCH PRODUCTS FOR POS - SHOWS ALL PRODUCTS
-------------------------------------------------------- */

$sql = "SELECT p.*, c.name as category_name, u.name as creator_name
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN users u ON p.created_by = u.id 
        WHERE p.stock_quantity > 0 
        ORDER BY p.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate cart totals for display
$cart_total = 0;
$cart_items = 0;
foreach ($_SESSION['pos_cart'] as $item) {
    $cart_total += $item['selling_price'] * $item['quantity'];
    $cart_items += $item['quantity'];
}

// Use different variable names for display to avoid conflicts
$display_discount = $_SESSION['pos_discount'];
$tax_rate = 0.16;
$display_tax = ($cart_total - $display_discount) * $tax_rate;
$display_net = $cart_total - $display_discount + $display_tax;
$display_cart_total = $cart_total;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .pos-container {
            display: flex;
            gap: 20px;
            min-height: 70vh;
        }
        .products-section {
            flex: 3;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
        }
        .cart-sidebar {
            flex: 1;
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .product-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        .pos-search {
            flex: 1;
        }
        .category-filter {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 15px 0;
        }
        .category-btn {
            padding: 5px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            background: white;
            cursor: pointer;
        }
        .category-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        .cart-header {
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .cart-items {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .quantity-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cart-summary {
            border-top: 2px solid #eee;
            padding-top: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .summary-total {
            font-size: 1.2rem;
            font-weight: bold;
            border-top: 2px solid #007bff;
            padding-top: 10px;
            margin-top: 10px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .action-buttons .btn {
            flex: 1;
        }
        .receipt-success {
            text-align: center;
            padding: 30px;
            background: #d4edda;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .receipt-details {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px auto;
            max-width: 400px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .payment-methods {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .payment-btn {
            padding: 5px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            background: white;
            cursor: pointer;
        }
        .payment-btn.active {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }
        .creator-info {
            font-size: 0.75rem;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .admin-product-indicator {
            background-color: #dc3545;
            color: white;
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 3px;
            margin-left: 5px;
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
                <div class="dashboard-header">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-cash-register me-2"></i>
                            POS System
                        </h1>
                        <p class="lead mb-0">Point of Sale - Sell products from all sellers</p>
                    </div>
                    <div class="text-end">
                        <?php if ($current_period): ?>
                            <div class="badge bg-primary fs-6 p-2">
                                <i class="fas fa-calendar me-1"></i>
                                Period: <?= $current_period['period_name'] ?>
                            </div>
                        <?php endif; ?>
                        <div class="badge bg-success fs-6 p-2 ms-2">
                            <i class="fas fa-shopping-cart me-1"></i>
                            <?= $cart_items ?> items
                        </div>
                    </div>
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

                <!-- Receipt Success -->
                <?php if ($receipt_number): ?>
                    <div class="receipt-success">
                        <i class="fas fa-receipt fa-3x mb-3"></i>
                        <h3>Sale Completed Successfully!</h3>
                        <p class="mb-2">Receipt Number: <strong><?= $receipt_number ?></strong></p>
                        <p class="mb-3">Total Amount: <strong>KSH <?= number_format($last_sale_amount, 2) ?></strong></p>
                        
                        <!-- Printable Receipt -->
                        <div class="receipt-details" id="receiptContent">
                            <div class="receipt-header">
                                <h4 class="mb-2"><?= $company_details['name'] ?></h4>
                                <p class="mb-1"><?= $company_details['address'] ?></p>
                                <p class="mb-1">Tel: <?= $company_details['phone'] ?></p>
                                <p class="mb-0">Email: <?= $company_details['email'] ?></p>
                            </div>
                            
                            <div class="receipt-items">
                                <div class="d-flex justify-content-between mb-2">
                                    <span><strong>Receipt:</strong> <?= $receipt_number ?></span>
                                    <span><strong>Date:</strong> <?= date('Y-m-d H:i:s') ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Items Sold:</strong>
                                </div>
                                <?php 
                                // We need to reconstruct the cart items from the transaction
                                $items_sql = "SELECT ti.*, p.name, p.sku 
                                            FROM transaction_items ti 
                                            JOIN products p ON ti.product_id = p.id 
                                            WHERE ti.transaction_id = (SELECT id FROM transactions WHERE receipt_number = ?)";
                                $items_stmt = $db->prepare($items_sql);
                                $items_stmt->bind_param("s", $receipt_number);
                                $items_stmt->execute();
                                $sold_items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                ?>
                                
                                <?php foreach ($sold_items as $item): ?>
                                    <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                                        <div>
                                            <div><?= htmlspecialchars($item['name']) ?></div>
                                            <small class="text-muted">Qty: <?= $item['quantity'] ?> × KSH <?= number_format($item['unit_price'], 2) ?></small>
                                        </div>
                                        <div>KSH <?= number_format($item['total_price'], 2) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="receipt-totals">
                                <div class="d-flex justify-content-between">
                                    <span>Subtotal:</span>
                                    <span>KSH <?= number_format($last_sale_amount / 1.16, 2) ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>VAT (16%):</span>
                                    <span>KSH <?= number_format(($last_sale_amount / 1.16) * 0.16, 2) ?></span>
                                </div>
                                <div class="d-flex justify-content-between fw-bold fs-5">
                                    <span>Total:</span>
                                    <span>KSH <?= number_format($last_sale_amount, 2) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <span>Payment Method:</span>
                                    <span class="text-uppercase"><?= $_SESSION['pos_payment_method'] ?></span>
                                </div>
                            </div>
                            
                            <div class="text-center mt-3 pt-3 border-top">
                                <p class="mb-1">Thank you for your business!</p>
                                <p class="mb-0 text-muted"><?= $company_details['name'] ?></p>
                            </div>
                        </div>
                        
                        <div class="action-buttons mt-3">
                            <button class="btn btn-primary" onclick="printReceipt()">
                                <i class="fas fa-print me-2"></i>Print Receipt
                            </button>
                            <?php if ($receipt_id > 0): ?>
                            <button class="btn btn-success" onclick="window.location.href='view_receipt.php?id=<?= $receipt_id ?>'">
                                <i class="fas fa-eye me-2"></i>View Saved Receipt
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Period Security Warning -->
                <?php displayPeriodSecurityWarning($user_id, $db); ?>

                <!-- POS Interface -->
                <div class="pos-container">
                    <!-- Products Section -->
                    <div class="products-section">
                        <!-- Search and Filters -->
                        <div class="d-flex gap-2 mb-3">
                            <input type="text" id="productSearch" class="form-control pos-search" placeholder="Search products...">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
                                <i class="fas fa-user me-2"></i>Customer
                            </button>
                        </div>

                        <!-- Category Filters -->
                        <div class="category-filter">
                            <button class="category-btn active" data-category="all">All Products</button>
                            <?php
                            $categories = [];
                            foreach ($products as $product) {
                                $categories[$product['category_name']] = $product['category_name'];
                            }
                            foreach ($categories as $category): ?>
                                <button class="category-btn" data-category="<?= htmlspecialchars($category) ?>">
                                    <?= htmlspecialchars($category) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Products Grid -->
                        <div class="products-grid" id="productsGrid">
                            <?php foreach ($products as $product): ?>
                                <div class="product-card" 
                                     data-product-id="<?= $product['id'] ?>" 
                                     data-category="<?= htmlspecialchars($product['category_name']) ?>"
                                     data-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"
                                     data-sku="<?= htmlspecialchars(strtolower($product['sku'])) ?>">
                                    <div class="text-center mb-2">
                                        <div class="product-icon bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px;">
                                            <i class="fas fa-box"></i>
                                        </div>
                                        <h6 class="mb-1"><?= htmlspecialchars($product['name']) ?></h6>
                                        <small class="text-muted d-block"><?= htmlspecialchars($product['sku']) ?></small>
                                        <div class="badge bg-info mb-1"><?= htmlspecialchars($product['category_name']) ?></div>
                                        <!-- Creator Information -->
                                        <div class="creator-info mb-1">
                                            <small>
                                                <i class="fas fa-user me-1"></i>
                                                <?= htmlspecialchars($product['creator_name'] ?? 'Unknown') ?>
                                                <?= $product['created_by'] != $user_id ? '<span class="admin-product-indicator">Shared</span>' : '' ?>
                                            </small>
                                        </div>
                                        <div class="mb-2">
                                            <strong class="text-success">KSH <?= number_format($product['selling_price'], 2) ?></strong>
                                        </div>
                                        <div class="mb-2">
                                            <span class="badge <?= $product['stock_quantity'] <= $product['min_stock'] ? 'bg-warning' : 'bg-success' ?>">
                                                Stock: <?= $product['stock_quantity'] ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" class="add-to-cart-form">
                                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="quantity" value="1" min="1" max="<?= $product['stock_quantity'] ?>" 
                                                   class="form-control" style="max-width: 80px;">
                                            <button type="submit" name="add_to_cart" class="btn btn-success btn-sm">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Cart Sidebar -->
                    <div class="cart-sidebar">
                        <div class="cart-header">
                            <h4 class="mb-0">
                                <i class="fas fa-shopping-cart me-2"></i>
                                Shopping Cart
                            </h4>
                            <small><?= count($_SESSION['pos_cart']) ?> items in cart</small>
                        </div>

                        <div class="cart-items">
                            <?php if (empty($_SESSION['pos_cart'])): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                                    <p>Your cart is empty</p>
                                    <p class="small">Add products from the left panel</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($_SESSION['pos_cart'] as $product_id => $item): ?>
                                    <div class="cart-item">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?= htmlspecialchars($item['name']) ?></h6>
                                            <small class="text-muted">SKU: <?= htmlspecialchars($item['sku']) ?></small>
                                            <div class="text-success">KSH <?= number_format($item['selling_price'], 2) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="quantity-controls mb-2">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                    <input type="hidden" name="quantity" value="<?= $item['quantity'] - 1 ?>">
                                                    <button type="submit" name="update_cart" class="quantity-btn" 
                                                            <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>
                                                        <i class="fas fa-minus"></i>
                                                    </button>
                                                </form>
                                                <span class="mx-2 fw-bold"><?= $item['quantity'] ?></span>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                    <input type="hidden" name="quantity" value="<?= $item['quantity'] + 1 ?>">
                                                    <button type="submit" name="update_cart" class="quantity-btn">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </form>
                                            </div>
                                            <div class="fw-bold">KSH <?= number_format($item['selling_price'] * $item['quantity'], 2) ?></div>
                                            <form method="POST" class="mt-1">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                <button type="submit" name="remove_from_cart" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="cart-summary">
                            <!-- Company Info -->
                            <div class="company-info mb-3">
                                <h6 class="mb-2">
                                    <i class="fas fa-store me-2"></i>
                                    <?= $company_details['name'] ?>
                                </h6>
                                <div class="small">
                                    <div><?= $company_details['address'] ?></div>
                                    <div><?= $company_details['phone'] ?></div>
                                </div>
                            </div>

                            <!-- Customer Info (Optional) -->
                            <div class="customer-info mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Customer (Optional)</h6>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                                <?php if ($_SESSION['pos_customer']): ?>
                                    <div class="small">
                                        <div><strong><?= htmlspecialchars($_SESSION['pos_customer']['name']) ?></strong></div>
                                        <?php if ($_SESSION['pos_customer']['phone']): ?>
                                            <div><?= htmlspecialchars($_SESSION['pos_customer']['phone']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($_SESSION['pos_customer']['email']): ?>
                                            <div><?= htmlspecialchars($_SESSION['pos_customer']['email']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small">Walk-in Customer</div>
                                <?php endif; ?>
                            </div>

                            <!-- Payment Method -->
                            <div class="mb-3">
                                <h6 class="mb-2">Payment Method</h6>
                                <div class="payment-methods">
                                    <?php 
                                    $payment_methods = [
                                        'cash' => 'Cash',
                                        'mpesa' => 'M-Pesa', 
                                        'card' => 'Card',
                                        'bank' => 'Bank Transfer'
                                    ];
                                    foreach ($payment_methods as $value => $label): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="payment_method" value="<?= $value ?>">
                                            <button type="submit" name="update_payment_method" 
                                                    class="payment-btn <?= $_SESSION['pos_payment_method'] === $value ? 'active' : '' ?>">
                                                <?= $label ?>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Discount -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Discount</h6>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#discountModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                                <div class="text-end">
                                    KSH <?= number_format($display_discount, 2) ?>
                                </div>
                            </div>

                            <!-- Order Summary -->
                            <div class="mb-3">
                                <div class="summary-row">
                                    <span>Subtotal:</span>
                                    <span>KSH <?= number_format($display_cart_total, 2) ?></span>
                                </div>
                                <div class="summary-row">
                                    <span>Discount:</span>
                                    <span>- KSH <?= number_format($display_discount, 2) ?></span>
                                </div>
                                <div class="summary-row">
                                    <span>VAT (16%):</span>
                                    <span>KSH <?= number_format($display_tax, 2) ?></span>
                                </div>
                                <div class="summary-row summary-total">
                                    <span>Total:</span>
                                    <span>KSH <?= number_format($display_net, 2) ?></span>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="action-buttons">
                                <form method="POST">
                                    <button type="submit" name="clear_cart" class="btn btn-outline-danger w-100" 
                                            <?= empty($_SESSION['pos_cart']) ? 'disabled' : '' ?>>
                                        <i class="fas fa-trash me-2"></i>Clear Cart
                                    </button>
                                </form>
                                <form method="POST">
                                    <button type="submit" name="complete_sale" class="btn btn-success w-100" 
                                            <?= empty($_SESSION['pos_cart']) || !$period_check['allowed'] ? 'disabled' : '' ?>>
                                        <i class="fas fa-check me-2"></i>Complete Sale
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalLabel">
                        <i class="fas fa-user me-2"></i>Customer Information (Optional)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Customer information is optional. Receipts will use company details.
                        </div>
                        <div class="mb-3">
                            <label for="customer_name" class="form-label">Customer Name</label>
                            <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                   value="<?= $_SESSION['pos_customer']['name'] ?? '' ?>" 
                                   placeholder="Walk-in Customer">
                        </div>
                        <div class="mb-3">
                            <label for="customer_phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="customer_phone" name="customer_phone" 
                                   value="<?= $_SESSION['pos_customer']['phone'] ?? '' ?>" 
                                   placeholder="+254...">
                        </div>
                        <div class="mb-3">
                            <label for="customer_email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="customer_email" name="customer_email" 
                                   value="<?= $_SESSION['pos_customer']['email'] ?? '' ?>" 
                                   placeholder="customer@example.com">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_customer" class="btn btn-primary">Save Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Discount Modal -->
    <div class="modal fade" id="discountModal" tabindex="-1" aria-labelledby="discountModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="discountModalLabel">
                        <i class="fas fa-tag me-2"></i>Apply Discount
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="discount" class="form-label">Discount Amount (KSH)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="discount" name="discount" 
                                   value="<?= $_SESSION['pos_discount'] ?>" 
                                   placeholder="0.00">
                            <div class="form-text">Enter the discount amount in Kenyan Shillings</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_discount" class="btn btn-primary">Apply Discount</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Product search functionality
        document.getElementById('productSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const productCards = document.querySelectorAll('.product-card');
            
            productCards.forEach(card => {
                const name = card.getAttribute('data-name');
                const sku = card.getAttribute('data-sku');
                
                if (name.includes(searchTerm) || sku.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Category filter functionality
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const selectedCategory = this.getAttribute('data-category');
                const productCards = document.querySelectorAll('.product-card');
                
                productCards.forEach(card => {
                    const category = card.getAttribute('data-category');
                    
                    if (selectedCategory === 'all' || category === selectedCategory) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });

        // Auto-focus on quantity input when product is clicked
        document.querySelectorAll('.product-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (!e.target.closest('.add-to-cart-form')) {
                    const input = this.querySelector('input[type="number"]');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }
            });
        });

        // Quick quantity shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey) {
                const focusedInput = document.activeElement;
                if (focusedInput && focusedInput.type === 'number' && focusedInput.closest('.product-card')) {
                    switch(e.key) {
                        case '1':
                            focusedInput.value = 1;
                            break;
                        case '2':
                            focusedInput.value = 2;
                            break;
                        case '5':
                            focusedInput.value = 5;
                            break;
                        case '0':
                            focusedInput.value = 10;
                            break;
                    }
                }
            }
        });

        // Auto-submit forms on quantity change for better UX
        document.querySelectorAll('input[name="quantity"]').forEach(input => {
            input.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Print receipt functionality
        function printReceipt() {
            const receiptContent = document.getElementById('receiptContent').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = receiptContent;
            window.print();
            document.body.innerHTML = originalContent;
            window.location.reload(); // Reload to restore functionality
        }

        // Show success message for a limited time
        <?php if ($message && !$receipt_number): ?>
            setTimeout(() => {
                const alert = document.querySelector('.alert-success');
                if (alert) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>