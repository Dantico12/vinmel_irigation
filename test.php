<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSeller() {
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'seller' || $_SESSION['role'] === 'admin');
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
$carry_result = null;

// Handle carry forward request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carry_forward'])) {
    $source_period_id = intval($_POST['source_period_id']);
    $target_period_id = intval($_POST['target_period_id']);
    
    if (empty($source_period_id) || empty($target_period_id)) {
        $error = "Please select both source and target periods";
    } elseif ($source_period_id === $target_period_id) {
        $error = "Source and target periods cannot be the same";
    } else {
        // Call the carry forward function
        $result = carryForwardProducts($source_period_id, $target_period_id, $user_id, $db);
        
        if ($result['success']) {
            $message = $result['message'];
            $carry_result = $result;
        } else {
            $error = $result['message'];
        }
    }
}

// Get all periods for dropdown
$periods_sql = "SELECT id, period_name, year, month, is_locked 
                FROM time_periods 
                WHERE created_by = ? 
                ORDER BY year DESC, month DESC";
$periods_stmt = $db->prepare($periods_sql);
$periods_stmt->bind_param("i", $user_id);
$periods_stmt->execute();
$periods_result = $periods_stmt->get_result();
$periods = $periods_result->fetch_all(MYSQLI_ASSOC);

/**
 * Improved carry forward function with better handling
 */
function carryForwardProducts($source_period_id, $target_period_id, $user_id, $db) {
    // Start transaction
    $db->begin_transaction();
    
    try {
        // Get source period info
        $source_sql = "SELECT * FROM time_periods WHERE id = ? AND created_by = ?";
        $source_stmt = $db->prepare($source_sql);
        $source_stmt->bind_param("ii", $source_period_id, $user_id);
        $source_stmt->execute();
        $source_period = $source_stmt->get_result()->fetch_assoc();
        
        if (!$source_period) {
            throw new Exception("Source period not found or access denied");
        }
        
        // Get target period info
        $target_sql = "SELECT * FROM time_periods WHERE id = ? AND created_by = ?";
        $target_stmt = $db->prepare($target_sql);
        $target_stmt->bind_param("ii", $target_period_id, $user_id);
        $target_stmt->execute();
        $target_period = $target_stmt->get_result()->fetch_assoc();
        
        if (!$target_period) {
            throw new Exception("Target period not found or access denied");
        }
        
        // Check if target period already has carried products
        $check_carried_sql = "SELECT COUNT(*) as count FROM products 
                             WHERE period_id = ? AND is_carried_forward = 1";
        $check_stmt = $db->prepare($check_carried_sql);
        $check_stmt->bind_param("i", $target_period_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        
        if ($check_result['count'] > 0) {
            throw new Exception("Target period already has carried-forward products. Please clear them first.");
        }
        
        // Get all active products from source period
        $products_sql = "SELECT * FROM products 
                        WHERE created_by = ? 
                        AND period_id = ? 
                        AND is_active = 1 
                        AND stock_quantity > 0
                        ORDER BY name ASC";
        $products_stmt = $db->prepare($products_sql);
        $products_stmt->bind_param("ii", $user_id, $source_period_id);
        $products_stmt->execute();
        $products_result = $products_stmt->get_result();
        
        $carried_count = 0;
        $carried_products = [];
        $total_value = 0;
        
        while ($product = $products_result->fetch_assoc()) {
            // Generate new SKU for carried product
            $new_sku = $product['sku'] . '-CF-' . $target_period['year'] . $target_period['month'];
            
            // Insert carried product
            $insert_sql = "INSERT INTO products (
                name, sku, category_id, cost_price, selling_price, 
                stock_quantity, min_stock, supplier, description, created_by,
                period_id, period_year, period_month, added_date, added_by,
                is_carried_forward, carried_from_period_id, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 1, ?, 1)";
            
            $insert_stmt = $db->prepare($insert_sql);
            $insert_stmt->bind_param(
                "ssiddiissiiiiiii",
                $product['name'],
                $new_sku,
                $product['category_id'],
                $product['cost_price'],
                $product['selling_price'],
                $product['stock_quantity'],
                $product['min_stock'],
                $product['supplier'],
                $product['description'],
                $user_id,
                $target_period_id,
                $target_period['year'],
                $target_period['month'],
                $user_id,
                $source_period_id
            );
            
            if ($insert_stmt->execute()) {
                $new_product_id = $insert_stmt->insert_id;
                $carried_count++;
                
                // Calculate carried value
                $carried_value = $product['stock_quantity'] * $product['cost_price'];
                $total_value += $carried_value;
                
                // Record in period_stock_carry table
                $carry_record_sql = "INSERT INTO period_stock_carry (
                    period_id, product_id, quantity, cost_price, carried_value
                ) VALUES (?, ?, ?, ?, ?)";
                
                $carry_record_stmt = $db->prepare($carry_record_sql);
                $carry_record_stmt->bind_param(
                    "iiidd",
                    $target_period_id,
                    $new_product_id,
                    $product['stock_quantity'],
                    $product['cost_price'],
                    $carried_value
                );
                $carry_record_stmt->execute();
                
                $carried_products[] = [
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'new_sku' => $new_sku,
                    'quantity' => $product['stock_quantity'],
                    'cost_price' => $product['cost_price'],
                    'value' => $carried_value
                ];
            }
        }
        
        // Create or update inventory period record
        $period_month = $target_period['year'] . '-' . str_pad($target_period['month'], 2, '0', STR_PAD_LEFT);
        
        // Check if inventory period record exists
        $check_inv_sql = "SELECT id FROM inventory_periods 
                         WHERE user_id = ? 
                         AND period_month = ?";
        $check_inv_stmt = $db->prepare($check_inv_sql);
        $check_inv_stmt->bind_param("is", $user_id, $period_month);
        $check_inv_stmt->execute();
        $inv_exists = $check_inv_stmt->get_result()->num_rows > 0;
        
        if ($inv_exists) {
            // Update existing inventory period
            $update_inv_sql = "UPDATE inventory_periods 
                              SET opening_balance = ?, current_inventory = ?
                              WHERE user_id = ? AND period_month = ?";
            $update_inv_stmt = $db->prepare($update_inv_sql);
            $update_inv_stmt->bind_param("ddis", $total_value, $total_value, $user_id, $period_month);
            $update_inv_stmt->execute();
        } else {
            // Create new inventory period
            $insert_inv_sql = "INSERT INTO inventory_periods (
                user_id, period_month, opening_balance, current_inventory, 
                closing_balance, total_sales, total_profit, status
            ) VALUES (?, ?, ?, ?, ?, 0, 0, 'active')";
            
            $insert_inv_stmt = $db->prepare($insert_inv_sql);
            $insert_inv_stmt->bind_param(
                "isddd",
                $user_id,
                $period_month,
                $total_value,
                $total_value,
                $total_value
            );
            $insert_inv_stmt->execute();
        }
        
        // Commit transaction
        $db->commit();
        
        return [
            'success' => true,
            'count' => $carried_count,
            'total_value' => $total_value,
            'products' => $carried_products,
            'source_period' => $source_period['period_name'],
            'target_period' => $target_period['period_name'],
            'message' => "Successfully carried forward {$carried_count} products from {$source_period['period_name']} to {$target_period['period_name']}. Total value: KSH " . number_format($total_value, 2)
        ];
        
    } catch (Exception $e) {
        $db->rollback();
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carry Forward Products - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .product-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .value-badge {
            font-size: 0.8rem;
            font-family: 'Courier New', monospace;
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
                        <h1 class="h2">Carry Forward Products Between Periods</h1>
                        <p class="lead mb-0">Transfer products from one period to another as opening stock</p>
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

                <div class="row">
                    <!-- Carry Forward Form -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-forward me-2"></i>Carry Forward Products</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="source_period_id" class="form-label">Source Period (From)</label>
                                        <select class="form-select" id="source_period_id" name="source_period_id" required>
                                            <option value="">Select Source Period</option>
                                            <?php foreach ($periods as $period): 
                                                if ($period['is_locked']): ?>
                                                    <option value="<?= $period['id'] ?>">
                                                        <?= $period['period_name'] ?> (Locked)
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Select the period to carry products from (usually a locked period)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="target_period_id" class="form-label">Target Period (To)</label>
                                        <select class="form-select" id="target_period_id" name="target_period_id" required>
                                            <option value="">Select Target Period</option>
                                            <?php foreach ($periods as $period): 
                                                if (!$period['is_locked']): ?>
                                                    <option value="<?= $period['id'] ?>">
                                                        <?= $period['period_name'] ?> (Active)
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Select the new/active period to carry products to</div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Note:</strong> This will create new product records in the target period with the same stock quantities from the source period. Original products in source period will not be modified.
                                    </div>
                                    
                                    <button type="submit" name="carry_forward" class="btn btn-primary btn-lg w-100" onclick="return confirm('Are you sure you want to carry forward products? This action cannot be undone.')">
                                        <i class="fas fa-forward me-2"></i>Carry Forward Products
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Instructions -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>How It Works</h5>
                            </div>
                            <div class="card-body">
                                <ol class="mb-4">
                                    <li class="mb-2">Select a <strong>locked period</strong> as the source (products will be copied from here)</li>
                                    <li class="mb-2">Select an <strong>active period</strong> as the target (products will be created here)</li>
                                    <li class="mb-2">Click "Carry Forward Products" to execute the transfer</li>
                                    <li class="mb-2">The system will create new product records in the target period</li>
                                    <li>Original products remain unchanged in the source period</li>
                                </ol>
                                
                                <div class="alert alert-warning">
                                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Important Notes:</h6>
                                    <ul class="mb-0">
                                        <li>Only products with positive stock quantities will be carried forward</li>
                                        <li>Carried products will have "-CF" appended to their SKU</li>
                                        <li>This operation records inventory value transfer for accounting</li>
                                        <li>Target period should not already have carried-forward products</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="card mt-4">
                            <div class="card-body">
                                <h6><i class="fas fa-chart-bar me-2"></i>Quick Statistics</h6>
                                <div class="row text-center mt-3">
                                    <div class="col-6">
                                        <div class="display-6 fw-bold text-primary">
                                            <?= count($periods) ?>
                                        </div>
                                        <small class="text-muted">Total Periods</small>
                                    </div>
                                    <div class="col-6">
                                        <div class="display-6 fw-bold text-success">
                                            <?= count(array_filter($periods, fn($p) => !$p['is_locked'])) ?>
                                        </div>
                                        <small class="text-muted">Active Periods</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Results Display -->
                <?php if ($carry_result && $carry_result['success']): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Carry Forward Results</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-success mb-4">
                                    <h6>Summary</h6>
                                    <p class="mb-0">
                                        <strong>From:</strong> <?= $carry_result['source_period'] ?><br>
                                        <strong>To:</strong> <?= $carry_result['target_period'] ?><br>
                                        <strong>Products Carried:</strong> <?= $carry_result['count'] ?><br>
                                        <strong>Total Value:</strong> KSH <?= number_format($carry_result['total_value'], 2) ?>
                                    </p>
                                </div>
                                
                                <h6>Carried Products Details</h6>
                                <div class="table-responsive product-list">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product Name</th>
                                                <th>Original SKU</th>
                                                <th>New SKU</th>
                                                <th>Quantity</th>
                                                <th>Cost Price</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($carry_result['products'] as $product): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($product['name']) ?></td>
                                                <td><span class="badge bg-secondary"><?= $product['sku'] ?></span></td>
                                                <td><span class="badge bg-success"><?= $product['new_sku'] ?></span></td>
                                                <td><?= $product['quantity'] ?></td>
                                                <td>KSH <?= number_format($product['cost_price'], 2) ?></td>
                                                <td>
                                                    <span class="badge bg-primary value-badge">
                                                        KSH <?= number_format($product['value'], 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="products.php?period_id=<?= $_POST['target_period_id'] ?>" class="btn btn-primary">
                                        <i class="fas fa-boxes me-2"></i>View Products in Target Period
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-select the latest locked period as source
        document.addEventListener('DOMContentLoaded', function() {
            const sourceSelect = document.getElementById('source_period_id');
            const targetSelect = document.getElementById('target_period_id');
            
            // Find locked periods and select the latest one
            let latestLockedId = null;
            for (let option of sourceSelect.options) {
                if (option.text.includes('(Locked)')) {
                    latestLockedId = option.value;
                }
            }
            if (latestLockedId) {
                sourceSelect.value = latestLockedId;
            }
            
            // Find active periods and select the first one
            let firstActiveId = null;
            for (let option of targetSelect.options) {
                if (option.text.includes('(Active)')) {
                    firstActiveId = option.value;
                    break;
                }
            }
            if (firstActiveId) {
                targetSelect.value = firstActiveId;
            }
        });
    </script>
</body>
</html>