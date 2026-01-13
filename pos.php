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
$receipt_id = 0;

// Company details
$company_details = [
    'name' => 'Vinmel Irrigation',
    'address' => 'Nairobi, Kenya',
    'phone' => '+254 700 000000',
    'email' => 'info@vinmel.com'
];

// Get current period
$current_period = getCurrentTimePeriod($user_id, $db);
$period_check = canModifyData($user_id, $db);

// Check if this is a print request
$is_print_request = isset($_GET['print_receipt']) && isset($_GET['receipt_number']);

/* -------------------------------------------------------
   RECEIPT FUNCTIONS
-------------------------------------------------------- */

/**
 * Generate receipt HTML for preview and printing
 */
function generateReceiptHTML($receipt_number, $transaction, $items, $company_details, $for_print = false) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Receipt <?= htmlspecialchars($receipt_number) ?></title>
        <link href="style.css" rel="stylesheet">
        <?php if ($for_print): ?>
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
                
                window.onafterprint = function() {
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            };
        </script>
        <?php endif; ?>
    </head>
    <body class="receipt-print-page">
        <?php if (!$for_print): ?>
        <div class="print-message">
            <i class="fas fa-print"></i> Ready to print receipt
        </div>
        <?php endif; ?>
        
        <div class="receipt-container">
            <div class="receipt-header">
                <h2><?= htmlspecialchars($company_details['name']) ?></h2>
                <p><?= htmlspecialchars($company_details['address']) ?></p>
                <p>Tel: <?= htmlspecialchars($company_details['phone']) ?></p>
                <p>Email: <?= htmlspecialchars($company_details['email']) ?></p>
            </div>
            
            <div class="receipt-divider"></div>
            
            <div class="receipt-info">
                <div class="receipt-info-row">
                    <span><strong>Receipt #:</strong></span>
                    <span><?= htmlspecialchars($receipt_number) ?></span>
                </div>
                <div class="receipt-info-row">
                    <span><strong>Date:</strong></span>
                    <span><?= date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])) ?></span>
                </div>
                <?php if (!empty($transaction['customer_name'])): ?>
                <div class="receipt-info-row">
                    <span><strong>Customer:</strong></span>
                    <span><?= htmlspecialchars($transaction['customer_name']) ?></span>
                </div>
                <?php endif; ?>
                <div class="receipt-info-row">
                    <span><strong>Seller:</strong></span>
                    <span><?= htmlspecialchars($transaction['seller_name']) ?></span>
                </div>
                <div class="receipt-info-row">
                    <span><strong>Payment:</strong></span>
                    <span><?= strtoupper(htmlspecialchars($transaction['payment_method'])) ?></span>
                </div>
            </div>
            
            <div class="receipt-divider"></div>
            
            <table class="receipt-items-table">
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
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="text-center"><?= $item['quantity'] ?></td>
                        <td class="text-right">KSh <?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-right">KSh <?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="receipt-divider"></div>
            
            <div class="receipt-totals">
                <div class="receipt-total-row">
                    <span>Subtotal:</span>
                    <span>KSh <?= number_format($transaction['total_amount'], 2) ?></span>
                </div>
                <?php if ($transaction['discount_amount'] > 0): ?>
                <div class="receipt-total-row">
                    <span>Discount:</span>
                    <span>- KSh <?= number_format($transaction['discount_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="receipt-grand-total">
                    <span>TOTAL:</span>
                    <span>KSh <?= number_format($transaction['net_amount'], 2) ?></span>
                </div>
            </div>
            
            <div class="receipt-divider"></div>
            
            <div class="receipt-footer">
                <p><strong>Thank you for your business!</strong></p>
                <p><?= htmlspecialchars($company_details['name']) ?></p>
                <p>Date Printed: <?= date('Y-m-d H:i:s') ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Generate receipt preview HTML
 */
function generateReceiptPreview($receipt_number, $transaction_data, $items, $company_details, $subtotal, $discount, $total) {
    ob_start();
    ?>
    <div class="receipt-preview-content">
        <!-- Company Header -->
        <div class="receipt-company">
            <h4 class="mb-2"><?= htmlspecialchars($company_details['name']) ?></h4>
            <p class="text-muted mb-1"><?= htmlspecialchars($company_details['address']) ?></p>
            <p class="text-muted mb-1">Tel: <?= htmlspecialchars($company_details['phone']) ?></p>
            <p class="text-muted mb-0">Email: <?= htmlspecialchars($company_details['email']) ?></p>
        </div>
        
        <!-- Receipt Info -->
        <div class="receipt-info">
            <div class="receipt-info-row">
                <span><strong>Receipt #:</strong></span>
                <span><?= htmlspecialchars($receipt_number) ?></span>
            </div>
            <div class="receipt-info-row">
                <span><strong>Date:</strong></span>
                <span><?= date('Y-m-d H:i:s') ?></span>
            </div>
            <?php if (!empty($transaction_data['customer_name'])): ?>
            <div class="receipt-info-row">
                <span><strong>Customer:</strong></span>
                <span><?= htmlspecialchars($transaction_data['customer_name']) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-info-row">
                <span><strong>Payment:</strong></span>
                <span class="text-uppercase"><?= htmlspecialchars($transaction_data['payment_method']) ?></span>
            </div>
        </div>
        
        <!-- Items Table -->
        <table class="receipt-items-table">
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
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($item['name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small>
                    </td>
                    <td class="text-center"><?= $item['quantity'] ?></td>
                    <td class="text-right">KSh <?= number_format($item['selling_price'], 2) ?></td>
                    <td class="text-right">KSh <?= number_format($item['selling_price'] * $item['quantity'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="receipt-totals-section">
            <div class="receipt-totals-row">
                <span>Subtotal:</span>
                <span>KSh <?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="receipt-totals-row">
                <span>Discount:</span>
                <span>- KSh <?= number_format($discount, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-grand-total">
                <span>TOTAL:</span>
                <span>KSh <?= number_format($total, 2) ?></span>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="receipt-footer">
            <p class="mb-1">Thank you for your business!</p>
            <p class="mb-0"><?= htmlspecialchars($company_details['name']) ?></p>
            <p class="mb-0">Date Previewed: <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Save receipt details - FIXED VERSION
 */
function saveReceipt($transaction_id, $receipt_number, $user_id, $db, $period_id = null, $payment_method = 'cash') {
    try {
        // Get transaction info
        $transaction_sql = "SELECT t.*, u.name as seller_name, c.name as customer_name, 
                                   c.phone as customer_phone, c.email as customer_email
                          FROM transactions t
                          LEFT JOIN users u ON t.user_id = u.id
                          LEFT JOIN customers c ON t.customer_id = c.id
                          WHERE t.id = ?";
        $stmt = $db->prepare($transaction_sql);
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();
        
        if (!$transaction) throw new Exception("Transaction not found");
        
        // Get transaction items from database
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
        
        // Validate payment method
        $payment_method = strtolower(trim($payment_method));
        if (!in_array($payment_method, ['cash', 'mpesa'])) {
            $payment_method = 'cash';
        }
        
        $company_details = [
            'name' => 'Vinmel Irrigation',
            'address' => 'Nairobi, Kenya',
            'phone' => '+254 700 000000',
            'email' => 'info@vinmel.com'
        ];
        
        // Generate receipt HTML
        $receipt_html = generateReceiptHTML($receipt_number, $transaction, $items, $company_details, false);
        
        $insert_sql = "INSERT INTO receipts (
            transaction_id, receipt_number, customer_name, customer_phone, customer_email,
            seller_id, seller_name, total_amount, discount_amount, net_amount,
            payment_method, transaction_date, items_json, receipt_html, period_id, company_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $db->prepare($insert_sql);
        $items_json = json_encode($items);
        $company_json = json_encode($company_details);
        $insert_stmt->bind_param(
            "issssissddssssis",
            $transaction_id,
            $receipt_number,
            $transaction['customer_name'],
            $transaction['customer_phone'],
            $transaction['customer_email'],
            $user_id,
            $transaction['seller_name'],
            $transaction['total_amount'],
            $transaction['discount_amount'],
            $transaction['net_amount'],
            $payment_method,
            $transaction['transaction_date'],
            $items_json,
            $receipt_html,
            $period_id,
            $company_json
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to save receipt: " . $insert_stmt->error);
        }
        
        return $insert_stmt->insert_id;
        
    } catch (Exception $e) {
        error_log("Error saving receipt: " . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------
   HANDLE PRINT RECEIPT REQUEST
-------------------------------------------------------- */

if ($is_print_request) {
    $receipt_number = $_GET['receipt_number'];
    $auto_print = isset($_GET['auto_print']) && $_GET['auto_print'] == '1';
    
    // First, try to get from receipts table
    $sql = "SELECT r.receipt_html, t.*, u.name as seller_name, c.name as customer_name, 
                   c.phone as customer_phone, c.email as customer_email
            FROM receipts r
            JOIN transactions t ON r.transaction_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN customers c ON t.customer_id = c.id
            WHERE r.receipt_number = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $receipt_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $receipt = $result->fetch_assoc();
        
        // Get items from receipt JSON
        $items = [];
        $items_json = $receipt['items_json'] ?? '[]';
        
        if (!empty($items_json) && $items_json !== '[]') {
            $items_data = json_decode($items_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($items_data)) {
                foreach ($items_data as $item) {
                    $items[] = [
                        'product_name' => $item['product_name'] ?? 'Unknown Product',
                        'sku' => $item['sku'] ?? '',
                        'quantity' => $item['quantity'] ?? 0,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'total_price' => $item['total_price'] ?? 0
                    ];
                }
            }
        }
        
        // Generate receipt with print styling
        $transaction_data = [
            'transaction_date' => $receipt['transaction_date'],
            'customer_name' => $receipt['customer_name'],
            'seller_name' => $receipt['seller_name'],
            'payment_method' => $receipt['payment_method'],
            'total_amount' => $receipt['total_amount'],
            'discount_amount' => $receipt['discount_amount'],
            'net_amount' => $receipt['net_amount']
        ];
        
        echo generateReceiptHTML($receipt_number, $transaction_data, $items, $company_details, $auto_print);
        exit();
    } else {
        // If not in receipts table, check if it's the current transaction
        if (isset($_SESSION['last_receipt_data']) && $_SESSION['last_receipt_data']['receipt_number'] == $receipt_number) {
            $data = $_SESSION['last_receipt_data'];
            
            // Create transaction data structure
            $transaction_data = [
                'transaction_date' => date('Y-m-d H:i:s'),
                'customer_name' => $data['customer_name'] ?? null,
                'seller_name' => $_SESSION['name'] ?? 'Seller',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'total_amount' => $data['subtotal'] ?? 0,
                'discount_amount' => $data['discount'] ?? 0,
                'net_amount' => $data['total'] ?? 0
            ];
            
            // Create items array from session
            $items = [];
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $items[] = [
                        'product_name' => $item['name'] ?? 'Unknown Product',
                        'sku' => $item['sku'] ?? '',
                        'quantity' => $item['quantity'] ?? 0,
                        'unit_price' => $item['selling_price'] ?? 0,
                        'total_price' => ($item['selling_price'] ?? 0) * ($item['quantity'] ?? 0)
                    ];
                }
            }
            
            echo generateReceiptHTML($receipt_number, $transaction_data, $items, $company_details, $auto_print);
            exit();
        }
    }
    
    // If nothing found, show error
    echo "<html><body><h1>Receipt not found: $receipt_number</h1></body></html>";
    exit();
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

// Handle add to cart with quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']) ?? 1;
    
    if ($quantity <= 0) {
        $error = "Quantity must be greater than 0!";
    } else {
        // Get products from current period
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.id = ? AND p.period_id = ? AND p.is_active = 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $product_id, $current_period['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $product = $result->fetch_assoc();
            
            if ($product['stock_quantity'] < $quantity) {
                $error = "Insufficient stock! Only {$product['stock_quantity']} units available.";
            } else {
                if (isset($_SESSION['pos_cart'][$product_id])) {
                    $_SESSION['pos_cart'][$product_id]['quantity'] += $quantity;
                } else {
                    $_SESSION['pos_cart'][$product_id] = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'selling_price' => $product['selling_price'],
                        'quantity' => $quantity,
                        'category' => $product['category_name'],
                        'description' => $product['description'] ?? '',
                        'is_carried_forward' => $product['is_carried_forward'] ?? 0
                    ];
                }
                $message = "{$product['name']} (x{$quantity}) added to cart!";
            }
        } else {
            $error = "Product not found in current period!";
        }
    }
}

// Handle update cart quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    
    if ($quantity <= 0) {
        unset($_SESSION['pos_cart'][$product_id]);
        $message = "Item removed from cart!";
    } else {
        if (isset($_SESSION['pos_cart'][$product_id])) {
            // Check stock
            $sql = "SELECT stock_quantity FROM products WHERE id = ? AND period_id = ? AND is_active = 1";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ii", $product_id, $current_period['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            
            if (!$product) {
                $error = "Product not available in current period!";
                unset($_SESSION['pos_cart'][$product_id]);
            } elseif ($product['stock_quantity'] >= $quantity) {
                $_SESSION['pos_cart'][$product_id]['quantity'] = $quantity;
                $message = "Cart updated!";
            } else {
                $error = "Insufficient stock! Only {$product['stock_quantity']} units available.";
            }
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
    $payment_method = trim($_POST['payment_method']);
    $payment_method = strtolower($payment_method);
    
    if (in_array($payment_method, ['cash', 'mpesa'])) {
        $_SESSION['pos_payment_method'] = $payment_method;
        $message = "Payment method updated!";
    } else {
        if ($payment_method === 'm-pesa' || $payment_method === 'm-pesa') {
            $_SESSION['pos_payment_method'] = 'mpesa';
            $message = "Payment method updated to M-Pesa!";
        } else {
            $_SESSION['pos_payment_method'] = 'cash';
            $error = "Invalid payment method! Defaulting to Cash.";
        }
    }
}

// Handle complete sale with receipt preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    if (empty($_SESSION['pos_cart'])) {
        $error = "Cart is empty! Add products to complete sale.";
    } else {
        // Calculate totals from session cart
        $subtotal = 0;
        $cart_items_detail = [];
        foreach ($_SESSION['pos_cart'] as $product_id => $item) {
            $item_total = $item['selling_price'] * $item['quantity'];
            $subtotal += $item_total;
            $cart_items_detail[] = [
                'product_id' => $product_id,
                'name' => $item['name'],
                'sku' => $item['sku'],
                'selling_price' => $item['selling_price'],
                'quantity' => $item['quantity'],
                'total' => $item_total,
                'is_carried_forward' => $item['is_carried_forward'] ?? 0
            ];
        }
        
        $discount_amount = $_SESSION['pos_discount'];
        $net_amount = $subtotal - $discount_amount;
        
        // Generate receipt number
        $receipt_number = 'RCP' . date('YmdHis') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        // Store for receipt preview
        $_SESSION['last_receipt_preview'] = [
            'receipt_number' => $receipt_number,
            'subtotal' => $subtotal,
            'discount' => $discount_amount,
            'total' => $net_amount,
            'customer_name' => $_SESSION['pos_customer']['name'] ?? null,
            'payment_method' => $_SESSION['pos_payment_method'],
            'items' => $cart_items_detail
        ];
        
        // Show receipt preview popup
        $show_receipt_preview = true;
    }
}
// Handle confirm sale after preview - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_sale'])) {
    if (isset($_SESSION['last_receipt_preview'])) {
        $receipt_data = $_SESSION['last_receipt_preview'];
        $receipt_number = $receipt_data['receipt_number'];
        
        // Start transaction
        $db->begin_transaction();
        
        try {
            // Get period ID if exists
            $period_id = $current_period ? $current_period['id'] : null;
            
            // Validate payment method
            $payment_method = strtolower(trim($receipt_data['payment_method']));
            
            if ($payment_method === 'm-pesa' || $payment_method === 'm-pesa') {
                $payment_method = 'mpesa';
            }
            
            if (!in_array($payment_method, ['cash', 'mpesa'])) {
                $payment_method = 'cash';
            }
            
            // FIXED: Get customer ID if exists
            $customer_id = null;
            if (isset($_SESSION['pos_customer']['name']) && !empty($_SESSION['pos_customer']['name'])) {
                // Try to find existing customer or insert new one
                $customer_name = $_SESSION['pos_customer']['name'];
                $customer_phone = $_SESSION['pos_customer']['phone'] ?? null;
                $customer_email = $_SESSION['pos_customer']['email'] ?? null;
                
                // Check if customer exists
                $customer_sql = "SELECT id FROM customers WHERE name = ? AND (phone = ? OR email = ?)";
                $customer_stmt = $db->prepare($customer_sql);
                $customer_stmt->bind_param("sss", $customer_name, $customer_phone, $customer_email);
                $customer_stmt->execute();
                $customer_result = $customer_stmt->get_result();
                
                if ($customer_result->num_rows > 0) {
                    $customer = $customer_result->fetch_assoc();
                    $customer_id = $customer['id'];
                } else if (!empty($customer_name)) {
                    // Insert new customer
                    $insert_customer_sql = "INSERT INTO customers (name, phone, email, created_at) VALUES (?, ?, ?, NOW())";
                    $insert_customer_stmt = $db->prepare($insert_customer_sql);
                    $insert_customer_stmt->bind_param("sss", $customer_name, $customer_phone, $customer_email);
                    if ($insert_customer_stmt->execute()) {
                        $customer_id = $db->insert_id;
                    }
                }
            }
            
            // FIXED: bind_param parameters - using s for NULL customer_id
            // Types: s = string, i = integer, d = double/float
            // Parameters: receipt_number(s), user_id(i), customer_id(i/null), total_amount(d), discount_amount(d), net_amount(d), payment_method(s), period_id(i)
            $transaction_sql = "INSERT INTO transactions (
                receipt_number, user_id, customer_id, total_amount, discount_amount, net_amount, 
                payment_method, transaction_date, time_period_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            
            $transaction_stmt = $db->prepare($transaction_sql);
            
            // FIXED: Proper type definition string - 'siidddssi' was wrong
            // We have: s (receipt_number), i (user_id), i (customer_id - but can be null), 
            // d (total_amount), d (discount_amount), d (net_amount), s (payment_method), i (period_id)
            // For NULL values, we need to use 'i' type but bind NULL value
            
            // Bind parameters properly
            $transaction_stmt->bind_param(
                "siidddsi",  // 7 types for 8 parameters (customer_id uses i type even if null)
                $receipt_number,
                $user_id,
                $customer_id,
                $receipt_data['subtotal'],
                $receipt_data['discount'],
                $receipt_data['total'],
                $payment_method,
                $period_id
            );
            
            if (!$transaction_stmt->execute()) {
                throw new Exception("Failed to create transaction: " . $transaction_stmt->error);
            }
            
            $transaction_id = $db->insert_id;
            
            // Insert transaction items and update stock
            foreach ($_SESSION['pos_cart'] as $product_id => $item) {
                // Check product exists in current period
                $check_sql = "SELECT id, stock_quantity, is_carried_forward FROM products WHERE id = ? AND period_id = ? AND is_active = 1";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->bind_param("ii", $product_id, $period_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $product_check = $check_result->fetch_assoc();
                
                if (!$product_check) {
                    throw new Exception("Product ID $product_id not available in current period");
                }
                
                if ($product_check['stock_quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock for product ID $product_id");
                }
                
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
                    throw new Exception("Failed to add transaction item: " . $item_stmt->error);
                }
                
                // Update product stock
                $update_stock_sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND period_id = ? AND is_active = 1";
                $update_stmt = $db->prepare($update_stock_sql);
                $update_stmt->bind_param("iii", $item['quantity'], $product_id, $period_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update product stock: " . $update_stmt->error);
                }
                
                if ($update_stmt->affected_rows === 0) {
                    throw new Exception("Could not update stock - product might not exist in current period");
                }
            }
            
            // Save receipt to database
            $receipt_id = saveReceipt($transaction_id, $receipt_number, $user_id, $db, $period_id, $payment_method);
            
            if ($receipt_id) {
                // Store receipt data for printing
                $_SESSION['last_receipt_data'] = [
                    'transaction_id' => $transaction_id,
                    'receipt_number' => $receipt_number,
                    'subtotal' => $receipt_data['subtotal'],
                    'discount' => $receipt_data['discount'],
                    'total' => $receipt_data['total'],
                    'customer_name' => $receipt_data['customer_name'] ?? null,
                    'payment_method' => $payment_method,
                    'items' => $receipt_data['items'],
                    'receipt_id' => $receipt_id
                ];
                
                $message = "Sale completed successfully! Receipt: $receipt_number";
                
                // Clear preview session
                unset($_SESSION['last_receipt_preview']);
                
                // Store flag to show print options after sale
                $_SESSION['show_print_options'] = true;
                $_SESSION['print_receipt_number'] = $receipt_number;
                
            } else {
                throw new Exception("Failed to save receipt to database");
            }
            
            // Commit transaction
            $db->commit();
            
            // Clear cart only after successful transaction
            $_SESSION['pos_cart'] = [];
            $_SESSION['pos_discount'] = 0;
            $_SESSION['pos_customer'] = null;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Transaction failed: " . $e->getMessage();
            unset($_SESSION['last_receipt_preview']);
            unset($_SESSION['last_receipt_data']);
            unset($_SESSION['show_print_options']);
        }
    }
}
// Handle cancel sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_sale'])) {
    unset($_SESSION['last_receipt_preview']);
    $message = "Sale cancelled. Cart preserved.";
}

// Handle clear cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cart'])) {
    $_SESSION['pos_cart'] = [];
    $_SESSION['pos_discount'] = 0;
    $_SESSION['pos_customer'] = null;
    $message = "Cart cleared!";
}

// Handle clear print options
if (isset($_GET['clear_print_session'])) {
    unset($_SESSION['show_print_options']);
    unset($_SESSION['print_receipt_number']);
    header('Location: pos.php');
    exit();
}

/* -------------------------------------------------------
   FETCH PRODUCTS
-------------------------------------------------------- */

if ($current_period) {
    $sql = "SELECT p.*, c.name as category_name, u.name as creator_name,
                   CASE 
                     WHEN p.is_carried_forward = 1 THEN 'Carried Forward'
                     ELSE 'Newly Added'
                   END as product_status
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN users u ON p.created_by = u.id 
            WHERE p.period_id = ? 
            AND p.stock_quantity > 0 
            AND p.is_active = 1
            ORDER BY p.name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $current_period['id']);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $products = [];
    $error = "No active period found! Please set up a time period first.";
}

// Calculate cart totals
$cart_total = 0;
$cart_items = 0;
foreach ($_SESSION['pos_cart'] as $item) {
    $cart_total += $item['selling_price'] * $item['quantity'];
    $cart_items += $item['quantity'];
}

$display_discount = $_SESSION['pos_discount'];
$display_net = $cart_total - $display_discount;
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
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header mb-4 pos-header">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-cash-register me-2"></i>
                            POS System
                        </h1>
                        <p class="lead mb-0">Professional Point of Sale - Current Period Inventory</p>
                    </div>
                    <div class="text-end">
                        <?php if ($current_period): ?>
                            <div class="badge bg-primary fs-6 p-2">
                                <i class="fas fa-calendar me-1"></i>
                                <?= $current_period['period_name'] ?>
                            </div>
                        <?php endif; ?>
                        <div class="badge bg-success fs-6 p-2 ms-2">
                            <i class="fas fa-shopping-cart me-1"></i>
                            <?= $cart_items ?> item<?= $cart_items != 1 ? 's' : '' ?>
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

                <?php if (!$current_period): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No active time period found. Please <a href="time_periods.php">create or activate a time period</a> first.
                    </div>
                <?php endif; ?>

                <!-- Period Security Warning -->
                <?php displayPeriodSecurityWarning($user_id, $db); ?>

                <!-- POS Interface -->
                <div class="pos-container">
                    <!-- Products Section -->
                    <div class="products-section">
                        <!-- Search and Filter -->
                        <div class="pos-search-container">
                            <div class="d-flex gap-2 mb-3">
                                <input type="text" id="productSearch" class="form-control pos-search" 
                                       placeholder="Search products by name or SKU...">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
                                    <i class="fas fa-user me-2"></i>Customer
                                </button>
                            </div>

                            <!-- Category Filter -->
                            <?php if ($products): ?>
                            <div class="category-filter">
                                <button class="category-btn active" data-category="all">All Products</button>
                                <?php
                                $categories = [];
                                foreach ($products as $product) {
                                    $category = $product['category_name'] ?? 'Uncategorized';
                                    if ($category && !in_array($category, $categories)) {
                                        $categories[] = $category;
                                    }
                                }
                                sort($categories);
                                foreach ($categories as $category): ?>
                                    <button class="category-btn" data-category="<?= htmlspecialchars($category) ?>">
                                        <?= htmlspecialchars($category) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Products Grid -->
                        <div class="products-grid" id="productsGrid">
                            <?php if (empty($products)): ?>
                                <div class="empty-products">
                                    <i class="fas fa-box-open"></i>
                                    <p>No products available in current period</p>
                                    <?php if ($current_period): ?>
                                        <p class="small">Add products to <?= htmlspecialchars($current_period['period_name']) ?> period first</p>
                                        <a href="products.php" class="btn btn-primary mt-2">
                                            <i class="fas fa-plus me-2"></i>Add Products
                                        </a>
                                    <?php else: ?>
                                        <p class="small">Please create or activate a time period first</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($products as $product): 
                                    $stock_status = $product['stock_quantity'] <= 0 ? 'out' : 
                                                   ($product['stock_quantity'] <= $product['min_stock'] ? 'low' : 'ok');
                                    $stock_color = $stock_status == 'ok' ? 'high' : 
                                                  ($stock_status == 'low' ? 'low' : 'medium');
                                    $is_carried = $product['is_carried_forward'] == 1;
                                ?>
                                    <div class="product-card <?= $is_carried ? 'carried-product' : '' ?>" 
                                         data-product-id="<?= $product['id'] ?>" 
                                         data-category="<?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>"
                                         data-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"
                                         data-sku="<?= htmlspecialchars(strtolower($product['sku'])) ?>"
                                         data-status="<?= $is_carried ? 'carried' : 'new' ?>">
                                        
                                        <div class="product-card-header">
                                            <div class="product-icon">
                                                <i class="fas fa-box"></i>
                                            </div>
                                            <div>
                                                <div class="product-name">
                                                    <?= htmlspecialchars($product['name']) ?>
                                                    <?php if ($is_carried): ?>
                                                        <span class="badge bg-info ms-1" title="Carried from previous period">
                                                            <i class="fas fa-exchange-alt"></i> Carried
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success ms-1" title="Newly added this period">
                                                            <i class="fas fa-plus"></i> New
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="product-sku">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($product['description'])): ?>
                                            <div class="product-description">
                                                <?= htmlspecialchars(substr($product['description'], 0, 100)) ?>
                                                <?= (strlen($product['description']) > 100) ? '...' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="product-price">KSH <?= number_format($product['selling_price'], 2) ?></div>
                                        
                                        <div class="stock-info">
                                            <span class="stock-indicator-dot <?= $stock_color ?>"></span>
                                            <span>Stock: <?= $product['stock_quantity'] ?></span>
                                            <?php if ($product['stock_quantity'] <= $product['min_stock']): ?>
                                                <small class="text-danger ms-1">(Low Stock)</small>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="quantity-section">
                                            <form method="POST" class="add-to-cart-form" onsubmit="return validateQuantity(this, <?= $product['stock_quantity'] ?>)">
                                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                
                                                <div class="quantity-control-group">
                                                    <div class="quantity-input-wrapper">
                                                        <button type="button" class="quantity-btn quantity-decrease" onclick="decreaseQuantity(this)">
                                                            <i class="fas fa-minus"></i>
                                                        </button>
                                                        <input type="number" name="quantity" value="1" min="1" 
                                                               max="<?= $product['stock_quantity'] ?>" 
                                                               class="form-control quantity-input"
                                                               onchange="updateTotalPrice(this, <?= $product['selling_price'] ?>, '<?= $product['id'] ?>')">
                                                        <button type="button" class="quantity-btn quantity-increase" onclick="increaseQuantity(this, <?= $product['stock_quantity'] ?>)">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="quantity-quick-buttons">
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 1)">1</button>
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 2)">2</button>
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 5)">5</button>
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 10)">10</button>
                                                </div>
                                                
                                                <div class="product-total-price" id="total-price-<?= $product['id'] ?>">
                                                    Total: KSH <?= number_format($product['selling_price'], 2) ?>
                                                </div>
                                                
                                                <button type="submit" name="add_to_cart" class="add-to-cart-btn">
                                                    <i class="fas fa-cart-plus me-2"></i>Add to Cart
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Cart Sidebar -->
                    <div class="cart-sidebar">
                        <div class="cart-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-1">
                                        <i class="fas fa-shopping-cart me-2"></i>
                                        Shopping Cart
                                    </h4>
                                    <small><?= $cart_items ?> item<?= $cart_items != 1 ? 's' : '' ?> in cart</small>
                                </div>
                                <?php if ($_SESSION['pos_customer'] && !empty($_SESSION['pos_customer']['name'])): ?>
                                    <div class="text-end">
                                        <small class="d-block">Customer:</small>
                                        <strong><?= htmlspecialchars($_SESSION['pos_customer']['name']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Cart Items -->
                        <div class="cart-items-container">
                            <?php if (empty($_SESSION['pos_cart'])): ?>
                                <div class="empty-cart">
                                    <i class="fas fa-shopping-cart"></i>
                                    <p>Your cart is empty</p>
                                    <p class="small">Add products from the first panel</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($_SESSION['pos_cart'] as $product_id => $item): 
                                    $item_total = $item['selling_price'] * $item['quantity'];
                                    $is_carried = $item['is_carried_forward'] ?? 0;
                                ?>
                                    <div class="cart-item <?= $is_carried ? 'carried-cart-item' : '' ?>">
                                        <div class="cart-item-details">
                                            <div class="cart-item-name">
                                                <?= htmlspecialchars($item['name']) ?>
                                                <?php if ($is_carried): ?>
                                                    <span class="badge bg-info ms-1" title="Carried from previous period">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="cart-item-meta">
                                                <span class="cart-item-price">
                                                    KSH <?= number_format($item['selling_price'], 2) ?>
                                                </span>
                                                <?php if (!empty($item['sku'])): ?>
                                                    <span class="cart-item-sku">SKU: <?= htmlspecialchars($item['sku']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="cart-item-quantity">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                <input type="hidden" name="quantity" value="<?= $item['quantity'] - 1 ?>">
                                                <button type="submit" name="update_cart" class="quantity-btn" 
                                                        <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>
                                                    <i class="fas fa-minus"></i>
                                                </button>
                                            </form>
                                            <span class="quantity-display"><?= $item['quantity'] ?></span>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                <input type="hidden" name="quantity" value="<?= $item['quantity'] + 1 ?>">
                                                <button type="submit" name="update_cart" class="quantity-btn">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <div class="cart-item-total">
                                            <div class="cart-total">KSH <?= number_format($item_total, 2) ?></div>
                                        </div>
                                        
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                            <button type="submit" name="remove_from_cart" class="btn btn-link p-0 text-danger">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Payment Method -->
                        <div class="payment-dropdown mb-3">
                            <h6 class="mb-2">
                                <i class="fas fa-money-bill-wave me-2"></i>Payment Method
                            </h6>
                            <form method="POST" id="paymentForm">
                                <select name="payment_method" class="form-select" onchange="this.form.submit()">
                                    <option value="cash" <?= $_SESSION['pos_payment_method'] === 'cash' ? 'selected' : '' ?>>
                                        💵 Walk-in (Cash)
                                    </option>
                                    <option value="mpesa" <?= $_SESSION['pos_payment_method'] === 'mpesa' ? 'selected' : '' ?>>
                                        📱 M-Pesa
                                    </option>
                                </select>
                                <input type="hidden" name="update_payment_method" value="1">
                            </form>
                        </div>

                        <!-- Discount -->
                        <div class="discount-section">
                            <form method="POST" class="d-flex w-100 gap-2">
                                <input type="number" name="discount" value="<?= $display_discount ?>" 
                                       step="0.01" min="0" class="form-control discount-input" 
                                       placeholder="Discount amount">
                                <button type="submit" name="update_discount" class="btn btn-outline-primary">
                                    <i class="fas fa-tag"></i> Apply
                                </button>
                            </form>
                        </div>

                        <!-- Cart Summary -->
                        <div class="cart-summary">
                            <div class="summary-row">
                                <span>Subtotal:</span>
                                <span>KSH <?= number_format($cart_total, 2) ?></span>
                            </div>
                            
                            <?php if ($display_discount > 0): ?>
                            <div class="summary-row text-danger">
                                <span>Discount:</span>
                                <span>- KSH <?= number_format($display_discount, 2) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="summary-row summary-total">
                                <span>TOTAL:</span>
                                <span>KSH <?= number_format($display_net, 2) ?></span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <form method="POST">
                                <button type="submit" name="clear_cart" class="btn btn-clear w-100" 
                                        <?= empty($_SESSION['pos_cart']) ? 'disabled' : '' ?>>
                                    <i class="fas fa-trash me-2"></i>Clear Cart
                                </button>
                            </form>
                            <form method="POST">
                                <button type="submit" name="complete_sale" class="btn btn-complete w-100" 
                                        <?= empty($_SESSION['pos_cart']) || !$period_check['allowed'] || !$current_period ? 'disabled' : '' ?>>
                                    <i class="fas fa-check me-2"></i>Complete Sale
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Preview Modal -->
    <?php if (isset($show_receipt_preview) && $show_receipt_preview && isset($_SESSION['last_receipt_preview'])): 
        $receipt_data = $_SESSION['last_receipt_preview'];
    ?>
        <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="receiptModalLabel">
                            <i class="fas fa-receipt me-2"></i>
                            Receipt Preview
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Review the receipt below before completing the sale.
                        </div>
                        
                        <div class="receipt-preview">
                            <?= generateReceiptPreview(
                                $receipt_data['receipt_number'],
                                [
                                    'customer_name' => $receipt_data['customer_name'],
                                    'payment_method' => $receipt_data['payment_method']
                                ],
                                $receipt_data['items'],
                                $company_details,
                                $receipt_data['subtotal'],
                                $receipt_data['discount'],
                                $receipt_data['total']
                            ) ?>
                        </div>
                        
                        <div class="receipt-actions">
                            <form method="POST" class="d-inline">
                                <button type="submit" name="cancel_sale" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel Sale
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <button type="submit" name="confirm_sale" class="btn btn-primary">
                                    <i class="fas fa-check me-2"></i>Confirm & Save
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Print Options Modal -->
    <?php if (isset($_SESSION['show_print_options']) && $_SESSION['show_print_options'] && isset($_SESSION['print_receipt_number'])): 
        $receipt_number = $_SESSION['print_receipt_number'];
    ?>
        <div class="modal fade" id="printOptionsModal" tabindex="-1" aria-labelledby="printOptionsModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="printOptionsModalLabel">
                            <i class="fas fa-print me-2"></i>
                            Print Receipt
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-success mb-3">
                            <i class="fas fa-check-circle me-2"></i>
                            Sale completed successfully! Receipt: <strong><?= $receipt_number ?></strong>
                        </div>
                        
                        <div class="printer-status printer-disconnected mb-3" id="printerStatus">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            No printer connected
                        </div>
                        
                        <h6 class="mb-3">Select Printing Method:</h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="print-option-card" onclick="selectPrintOption('browser')" id="browserOption">
                                    <div class="text-center">
                                        <div class="print-option-icon">
                                            <i class="fas fa-print"></i>
                                        </div>
                                        <h6>Browser Print</h6>
                                        <p class="small text-muted">Print using your browser's print dialog</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <div class="print-option-card" onclick="selectPrintOption('popup')" id="popupOption">
                                    <div class="text-center">
                                        <div class="print-option-icon">
                                            <i class="fas fa-window-maximize"></i>
                                        </div>
                                        <h6>Popup Print</h6>
                                        <p class="small text-muted">Open in new window for automatic printing</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 mt-3">
                            <button class="btn btn-primary" onclick="printSelectedOption()" id="printBtn">
                                <i class="fas fa-print me-2"></i>Print Receipt
                            </button>
                            <button class="btn btn-outline-secondary" onclick="skipPrinting()">
                                <i class="fas fa-forward me-2"></i>Skip Printing
                            </button>
                            <button class="btn btn-outline-info" onclick="viewReceipt('<?= $receipt_number ?>')">
                                <i class="fas fa-eye me-2"></i>View Receipt Details
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Customer Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Customer Information
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   value="<?= $_SESSION['pos_customer']['name'] ?? '' ?>" 
                                   placeholder="Walk-in Customer">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="customer_phone" 
                                   value="<?= $_SESSION['pos_customer']['phone'] ?? '' ?>" 
                                   placeholder="+254...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="customer_email" 
                                   value="<?= $_SESSION['pos_customer']['email'] ?? '' ?>" 
                                   placeholder="customer@example.com">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_customer" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global variables for printing
    let selectedPrintOption = 'browser';
    let receiptNumber = '<?= isset($_SESSION['print_receipt_number']) ? $_SESSION['print_receipt_number'] : '' ?>';

    // Initialize modals on page load
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($show_receipt_preview) && $show_receipt_preview): ?>
        // Show receipt preview modal
        const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
        receiptModal.show();
        <?php endif; ?>

        <?php if (isset($_SESSION['show_print_options']) && $_SESSION['show_print_options']): ?>
        // Show print options modal after successful sale
        const printModal = new bootstrap.Modal(document.getElementById('printOptionsModal'));
        printModal.show();
        <?php endif; ?>

        // Auto-select browser print option
        selectPrintOption('browser');
        
        // Initialize total prices
        document.querySelectorAll('.add-to-cart-form').forEach(form => {
            const input = form.querySelector('.quantity-input');
            const card = form.closest('.product-card');
            const priceElement = card ? card.querySelector('.product-price') : null;
            const priceText = priceElement ? priceElement.textContent : '';
            const unitPrice = parseFloat(priceText.replace('KSH', '').replace(',', '').trim());
            const productId = form.querySelector('input[name="product_id"]').value;
            
            if (input && !isNaN(unitPrice)) {
                updateTotalPrice(input, unitPrice, productId);
            }
        });
    });

    // Quantity control functions
    function decreaseQuantity(button) {
        const input = button.closest('.quantity-input-wrapper').querySelector('.quantity-input');
        const currentValue = parseInt(input.value) || 1;
        if (currentValue > 1) {
            input.value = currentValue - 1;
            input.dispatchEvent(new Event('change'));
        }
    }

    function increaseQuantity(button, maxStock) {
        const input = button.closest('.quantity-input-wrapper').querySelector('.quantity-input');
        const currentValue = parseInt(input.value) || 1;
        if (currentValue < maxStock) {
            input.value = currentValue + 1;
            input.dispatchEvent(new Event('change'));
        } else {
            alert(`Maximum stock available: ${maxStock}`);
        }
    }

    function setQuickQuantity(button, quantity) {
        const form = button.closest('.add-to-cart-form');
        const input = form.querySelector('.quantity-input');
        const maxStock = parseInt(input.getAttribute('max')) || 999;
        
        if (quantity <= maxStock) {
            input.value = quantity;
            input.dispatchEvent(new Event('change'));
        } else {
            input.value = maxStock;
            input.dispatchEvent(new Event('change'));
            alert(`Maximum stock available: ${maxStock}`);
        }
    }

    function updateTotalPrice(input, unitPrice, productId) {
        const quantity = parseInt(input.value) || 1;
        const total = quantity * unitPrice;
        const totalElement = document.getElementById(`total-price-${productId}`);
        if (totalElement) {
            totalElement.textContent = `Total: KSH ${total.toFixed(2)}`;
        }
    }

    function validateQuantity(form, maxStock) {
        const input = form.querySelector('.quantity-input');
        const quantity = parseInt(input.value) || 0;
        
        if (quantity <= 0) {
            alert('Quantity must be greater than 0');
            input.focus();
            return false;
        }
        
        if (quantity > maxStock) {
            alert(`Only ${maxStock} units available in stock`);
            input.value = maxStock;
            const productId = form.querySelector('input[name="product_id"]').value;
            const card = form.closest('.product-card');
            const priceElement = card ? card.querySelector('.product-price') : null;
            const priceText = priceElement ? priceElement.textContent : '';
            const unitPrice = parseFloat(priceText.replace('KSH', '').replace(',', '').trim());
            updateTotalPrice(input, unitPrice, productId);
            return false;
        }
        
        return true;
    }

    // Product search
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

    // Category filter
    document.querySelectorAll('.category-btn').forEach(btn => {
        btn.addEventListener('click', function() {
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

    // Print Options Functions
    function selectPrintOption(option) {
        // Remove selected class from all options
        document.querySelectorAll('.print-option-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        // Add selected class to chosen option
        const optionElement = document.getElementById(option + 'Option');
        if (optionElement) {
            optionElement.classList.add('selected');
            selectedPrintOption = option;
            
            // Update print button text
            const printBtn = document.getElementById('printBtn');
            if (option === 'browser') {
                printBtn.innerHTML = '<i class="fas fa-print me-2"></i>Print with Browser';
            } else if (option === 'popup') {
                printBtn.innerHTML = '<i class="fas fa-window-maximize me-2"></i>Open Print Window';
            }
        }
    }

    function skipPrinting() {
        // Clear the session flag
        fetch('pos.php?clear_print_session=1').then(() => {
            // Hide the modal properly
            const modal = bootstrap.Modal.getInstance(document.getElementById('printOptionsModal'));
            if (modal) {
                modal.hide();
            }
        });
    }

    function viewReceipt(receiptNumber) {
        window.open('pos.php?print_receipt=1&receipt_number=' + encodeURIComponent(receiptNumber), '_blank');
    }

    // Print function
    function printSelectedOption() {
        if (!receiptNumber) {
            alert('No receipt number available');
            return;
        }
        
        switch(selectedPrintOption) {
            case 'browser':
                printReceiptBrowser(receiptNumber);
                break;
            case 'popup':
                printReceiptPopup(receiptNumber);
                break;
            default:
                printReceiptBrowser(receiptNumber);
        }
    }

    function printReceiptBrowser(receiptNumber) {
        // Open receipt in new tab for printing
        const printWindow = window.open('pos.php?print_receipt=1&receipt_number=' + encodeURIComponent(receiptNumber), '_blank');
        if (printWindow) {
            printWindow.focus();
            // Wait for window to load then trigger print
            setTimeout(() => {
                printWindow.print();
            }, 1000);
        }
        // Close the modal
        skipPrinting();
    }

    function printReceiptPopup(receiptNumber) {
        // Open receipt in new tab with auto-print
        const printWindow = window.open('pos.php?print_receipt=1&receipt_number=' + encodeURIComponent(receiptNumber) + '&auto_print=1', '_blank');
        if (printWindow) {
            printWindow.focus();
        }
        // Close the modal
        skipPrinting();
    }

    // Auto-hide success messages
    <?php if ($message && !isset($show_receipt_preview)): ?>
    setTimeout(() => {
        const alert = document.querySelector('.alert-success');
        if (alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }
    }, 5000);
    <?php endif; ?>
    </script>
</body>
</html>