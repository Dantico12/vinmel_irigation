<?php
// view_quotation.php
// Start secure session
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict'
]);

// Security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'security.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Database connection
$database = new Database();
$conn = $database->getConnection();

// Get current user
$user_id = intval($_SESSION['user_id']);
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Verify user exists
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Check if quotation ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Quotation ID is required.";
    header('Location: quotation.php');
    exit();
}

$quotation_id = intval($_GET['id']);

// DEBUG: Log the quotation ID and user ID
error_log("DEBUG: View quotation request - Quotation ID: $quotation_id, User ID: $user_id");

// Get quotation details with template information
$stmt = $conn->prepare("
    SELECT q.*, t.template_name, t.items_json as template_items_json, 
           t.hybrid_config as template_hybrid_config, t.is_hybrid as template_is_hybrid
    FROM irrigation_quotations q
    LEFT JOIN irrigation_templates t ON q.template_id = t.id
    WHERE q.id = ? AND q.user_id = ?
");
$stmt->bind_param("ii", $quotation_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$quotation = $result->fetch_assoc();
$stmt->close();

if (!$quotation) {
    // Check if quotation exists at all
    $stmt = $conn->prepare("SELECT id, user_id FROM irrigation_quotations WHERE id = ?");
$stmt->bind_param("i", $quotation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $quotation_exists = $result->fetch_assoc();
    $stmt->close();
    
    error_log("DEBUG: Quotation check - Found: " . ($quotation_exists ? 'Yes' : 'No'));
    
    if ($quotation_exists) {
        error_log("DEBUG: Quotation exists but user mismatch - Quotation User ID: " . $quotation_exists['user_id'] . ", Current User ID: $user_id");
        if ($quotation_exists['user_id'] != $user_id) {
            $_SESSION['error_message'] = "You don't have permission to view this quotation.";
        } else {
            $_SESSION['error_message'] = "Quotation not found or access denied.";
        }
    } else {
        $_SESSION['error_message'] = "Quotation not found in database.";
    }
    header('Location: quotation.php');
    exit();
}

// DEBUG: Log quotation found
error_log("DEBUG: Quotation found - Number: " . $quotation['quotation_number']);

// Determine if this is a hybrid quotation
$is_hybrid = ($quotation['is_hybrid'] ?? 0) == 1 || ($quotation['template_is_hybrid'] ?? 0) == 1;

// Initialize arrays
$items = [];
$hybrid_config = [];
$all_items = []; // This will hold all items for display
$subtotal = 0;

// Process items based on quotation type
if ($is_hybrid) {
    // Handle hybrid quotation
    // First check if hybrid_config exists in quotation
    if (!empty($quotation['hybrid_config'])) {
        $hybrid_config = json_decode($quotation['hybrid_config'], true);
    } 
    // If not in quotation, check template
    elseif (!empty($quotation['template_hybrid_config'])) {
        $hybrid_config = json_decode($quotation['template_hybrid_config'], true);
    }
    
    // Extract items from hybrid config
    if (!empty($hybrid_config) && isset($hybrid_config['varieties'])) {
        foreach ($hybrid_config['varieties'] as $variety) {
            if (isset($variety['items']) && is_array($variety['items'])) {
                $variety_name = $variety['crop_variety'] ?? $variety['variety_name'] ?? 'Unknown';
                foreach ($variety['items'] as $item) {
                    // Add variety prefix to description
                    $item['description'] = '[' . $variety_name . '] ' . ($item['description'] ?? 'Item');
                    $all_items[] = $item;
                    // Calculate amount for subtotal
                    $quantity = floatval($item['quantity'] ?? 0);
                    $rate = floatval($item['rate'] ?? 0);
                    $amount = floatval($item['amount'] ?? ($quantity * $rate));
                    $subtotal += $amount;
                }
            }
        }
        error_log("DEBUG: Found " . count($all_items) . " items in hybrid config");
    }
} else {
    // Handle regular quotation
    // First check if items are in quotation
    if (!empty($quotation['items_json'])) {
        $items = json_decode($quotation['items_json'], true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($items)) {
            $all_items = $items;
            error_log("DEBUG: Found " . count($all_items) . " items in quotation items_json");
        }
    }
    
    // If no items in quotation, check template
    if (empty($all_items) && !empty($quotation['template_items_json'])) {
        $template_items = json_decode($quotation['template_items_json'], true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($template_items)) {
            $all_items = $template_items;
            error_log("DEBUG: Found " . count($all_items) . " items in template");
        }
    }
    
    // Last resort: check if items_json might be malformed or stored differently
    if (empty($all_items) && !empty($quotation['items_json'])) {
        $items_json = $quotation['items_json'];
        // Try to fix common JSON issues
        $items_json = str_replace("'", '"', $items_json);
        $items_json = preg_replace('/,\s*([}\]])/', '$1', $items_json);
        $items = json_decode($items_json, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($items)) {
            $all_items = $items;
            error_log("DEBUG: Fixed JSON and found " . count($all_items) . " items");
        }
    }
}

// If still no items, try one more check in the database
if (empty($all_items) && isset($quotation['template_id'])) {
    // Check if there are template_crop_varieties for this quotation
    $stmt = $conn->prepare("
        SELECT * FROM template_crop_varieties 
        WHERE template_id = ? 
        ORDER BY id
    ");
    $stmt->bind_param("i", $quotation['template_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $varieties = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($varieties as $variety) {
        if (!empty($variety['items_json'])) {
            $variety_items = json_decode($variety['items_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($variety_items)) {
                $variety_name = $variety['crop_variety'] ?? 'Unknown';
                foreach ($variety_items as $item) {
                    $item['description'] = '[' . $variety_name . '] ' . ($item['description'] ?? 'Item');
                    $all_items[] = $item;
                    // Calculate amount for subtotal
                    $quantity = floatval($item['quantity'] ?? 0);
                    $rate = floatval($item['rate'] ?? 0);
                    $amount = floatval($item['amount'] ?? ($quantity * $rate));
                    $subtotal += $amount;
                }
            }
        }
    }
    
    if (!empty($all_items)) {
        error_log("DEBUG: Found " . count($all_items) . " items in template_crop_varieties");
    }
}

// Calculate subtotal if not already calculated
if ($subtotal == 0 && !empty($all_items)) {
    foreach ($all_items as $item) {
        $quantity = floatval($item['quantity'] ?? 0);
        $rate = floatval($item['rate'] ?? 0);
        $amount = floatval($item['amount'] ?? ($quantity * $rate));
        $subtotal += $amount;
    }
}

// DEBUG: Show what we're getting from database
error_log("DEBUG: From DB - total_material: " . ($quotation['total_material'] ?? 'null'));
error_log("DEBUG: From DB - labor_cost: " . ($quotation['labor_cost'] ?? 'null'));
error_log("DEBUG: From DB - discount_amount: " . ($quotation['discount_amount'] ?? 'null'));
error_log("DEBUG: From DB - tax_amount: " . ($quotation['tax_amount'] ?? 'null'));
error_log("DEBUG: Calculated subtotal from items: " . $subtotal);

// FIXED: Always use calculated subtotal for materials, NOT from database
$total_material = $subtotal; // Use the actual calculated subtotal from items

// Get percentages from database
$labor_percentage = floatval($quotation['labor_percentage'] ?? 0);
$discount_percentage = floatval($quotation['discount_percentage'] ?? 0);
$tax_rate = floatval($quotation['tax_rate'] ?? 0);

// Calculate costs based on actual percentages
$labor_cost = ($labor_percentage / 100) * $total_material;
$calculated_subtotal = $total_material + $labor_cost;
$discount_amount = ($discount_percentage / 100) * $calculated_subtotal;
$taxable = $calculated_subtotal - $discount_amount;
$tax_amount = ($tax_rate / 100) * $taxable;
$grand_total = $taxable + $tax_amount;

// But show what's in database for debugging
$db_total_material = $quotation['total_material'] ?? 0;
$db_labor_cost = $quotation['labor_cost'] ?? 0;
$db_discount_amount = $quotation['discount_amount'] ?? 0;
$db_tax_amount = $quotation['tax_amount'] ?? 0;
$db_grand_total = $quotation['grand_total'] ?? 0;

// DEBUG: Show calculated vs database values
error_log("DEBUG: Calculated - total_material: $total_material, DB: $db_total_material");
error_log("DEBUG: Calculated - labor_cost: $labor_cost, DB: $db_labor_cost");
error_log("DEBUG: Calculated - discount_amount: $discount_amount, DB: $db_discount_amount");
error_log("DEBUG: Calculated - tax_amount: $tax_amount, DB: $db_tax_amount");
error_log("DEBUG: Calculated - grand_total: $grand_total, DB: $db_grand_total");

// Get period information if available
$period_info = null;
if (isset($quotation['period_id'])) {
    $stmt = $conn->prepare("SELECT * FROM time_periods WHERE id = ?");
    $stmt->bind_param("i", $quotation['period_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $period_info = $result->fetch_assoc();
    $stmt->close();
}

// Final debug
error_log("DEBUG: Total items to display: " . count($all_items));
error_log("DEBUG: Subtotal: " . $subtotal);
error_log("DEBUG: Is Hybrid: " . ($is_hybrid ? 'Yes' : 'No'));
error_log("DEBUG: Labor %: " . $labor_percentage);
error_log("DEBUG: Discount %: " . $discount_percentage);
error_log("DEBUG: Tax %: " . $tax_rate);

include 'nav_bar.php';
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Quotation | Vinmel Irrigation</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
    <!-- jsPDF and html2canvas libraries for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    
    <style>
        .quotation-header {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-md);
        }
        
        .quotation-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .quotation-badge.hybrid {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-purple));
            color: white;
        }
        
        .quotation-badge.drip {
            background: var(--primary-blue);
            color: white;
        }
        
        .quotation-badge.sprinkler {
            background: var(--secondary-green);
            color: white;
        }
        
        .quotation-badge.rain_hose {
            background: var(--accent-orange);
            color: white;
        }
        
        .back-btn {
            background: var(--light-bg);
            border: 1px solid var(--border-color);
            color: var(--medium-text);
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-md);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition-fast);
        }
        
        .back-btn:hover {
            background: var(--primary-blue);
            color: var(--white);
            border-color: var(--primary-blue);
        }
        
        .action-buttons {
            display: flex;
            gap: var(--space-sm);
            flex-wrap: wrap;
        }
        
        .items-table th {
            background: var(--light-bg);
            border-bottom: 2px solid var(--border-color);
        }
        
        .total-row {
            background: var(--primary-blue);
            color: white;
            font-size: 1.2rem;
        }
        
        .amount-cell {
            text-align: right;
            font-weight: bold;
        }
        
        .print-only {
            display: none;
        }
        
        .pdf-only {
            display: none;
        }
        
        .quotation-logo {
            max-width: 200px;
            margin-bottom: 20px;
        }
        
        .variety-badge {
            background: var(--light-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            color: var(--medium-text);
        }
        
        .pdf-loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            color: white;
            font-size: 1.5rem;
            flex-direction: column;
            gap: 1rem;
        }
        
        .pdf-loading .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .action-buttons {
                display: none !important;
            }
            
            .back-btn {
                display: none !important;
            }
            
            .print-only {
                display: block !important;
            }
            
            .pdf-only {
                display: none !important;
            }
            
            .quotation-header {
                border: none;
                box-shadow: none;
            }
            
            .container {
                width: 100%;
                max-width: none;
            }
            
            body {
                font-size: 12px;
                color: #000;
            }
            
            .table {
                font-size: 11px;
            }
        }
        
        /* Warning for incorrect calculations */
        .calculation-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .calculation-warning h5 {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Main Content -->
        <main class="main-content">
            <div class="content-area">
                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message'], ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <!-- Calculation Warning -->
                <?php 
                // Check if database values differ significantly from calculated values
                $material_diff = abs($total_material - $db_total_material);
                $is_incorrect = $material_diff > 100; // If difference is more than 100 KES
                
                if ($is_incorrect && !empty($db_total_material)): 
                ?>
                <div class="calculation-warning">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i> Calculation Discrepancy Detected</h5>
                    <p class="mb-2">The quotation has incorrect calculations stored in the database.</p>
                    <p class="mb-0"><strong>Items Total:</strong> KES <?php echo number_format($subtotal, 2); ?> | 
                    <strong>DB Materials:</strong> KES <?php echo number_format($db_total_material, 2); ?></p>
                    <p class="mb-0 small text-muted">Showing corrected calculations based on actual items total.</p>
                </div>
                <?php endif; ?>

                <!-- Back Button -->
                <div class="mb-4 no-print">
                    <a href="quotation.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Templates
                    </a>
                </div>

                <!-- Main Quotation Content for PDF Generation -->
                <div id="quotation-content">
                    <!-- Print Header (only shows when printing) -->
                    <div class="print-only">
                        <div class="text-center mb-4">
                            <h1>VINMEL IRRIGATION</h1>
                            <p class="mb-1">Professional Irrigation Systems</p>
                            <p class="mb-1">Direct Legal Development Program: 2019-2020/2020</p>
                            <p class="mb-1">https://cjr.ipm</p>
                            <hr>
                        </div>
                    </div>
                    
                    <!-- PDF Header (only for PDF generation) -->
                    <div class="pdf-only">
                        <div class="text-center mb-4">
                            <h1 style="color: #2c3e50; font-size: 28px;">VINMEL IRRIGATION</h1>
                            <p style="color: #7f8c8d; font-size: 14px; margin-bottom: 5px;">Professional Irrigation Systems</p>
                            <p style="color: #7f8c8d; font-size: 12px; margin-bottom: 5px;">Direct Legal Development Program: 2019-2020/2020</p>
                            <p style="color: #7f8c8d; font-size: 12px;">https://cjr.ipm</p>
                            <hr style="border-color: #ddd; margin: 15px 0;">
                        </div>
                    </div>
                    
                    <!-- Quotation Header -->
                    <div class="quotation-header">
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <?php
                                $badge_class = $is_hybrid ? 'hybrid' : ($quotation['irrigation_type'] ?? 'drip');
                                $irrigation_type_display = ucfirst($quotation['irrigation_type'] ?? 'drip');
                                if ($is_hybrid) {
                                    $irrigation_type_display = 'Hybrid System';
                                }
                                ?>
                                <span class="quotation-badge <?php echo $badge_class; ?>">
                                    <?php echo strtoupper($irrigation_type_display); ?> QUOTATION
                                </span>
                                <h1 class="mb-2">Quotation: <?php echo htmlspecialchars($quotation['quotation_number']); ?></h1>
                                <p class="text-muted mb-3">Created: <?php echo date('F d, Y', strtotime($quotation['created_at'])); ?></p>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong>Project:</strong> 
                                            <?php echo htmlspecialchars($quotation['project_name']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Customer:</strong> 
                                            <?php echo htmlspecialchars($quotation['customer_name'] ?: 'Not specified'); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Location:</strong> 
                                            <?php echo htmlspecialchars($quotation['location'] ?: 'Not specified'); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong>Land Size:</strong> 
                                            <?php echo number_format($quotation['land_size'], 2); ?> <?php echo htmlspecialchars($quotation['land_unit']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Crop Type:</strong> 
                                            <?php echo ucfirst(htmlspecialchars($quotation['crop_type'] ?? 'Multiple')); ?>
                                        </div>
                                        <?php if (!empty($quotation['template_name'])): ?>
                                        <div class="mb-2">
                                            <strong>Template Used:</strong> 
                                            <?php echo htmlspecialchars($quotation['template_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h5 class="card-title">Quotation Summary</h5>
                                        <div class="mb-2 d-flex justify-content-between">
                                            <span>Quotation #:</span>
                                            <span class="fw-bold"><?php echo htmlspecialchars($quotation['quotation_number']); ?></span>
                                        </div>
                                        <div class="mb-2 d-flex justify-content-between">
                                            <span>Date:</span>
                                            <span><?php echo date('M d, Y', strtotime($quotation['created_at'])); ?></span>
                                        </div>
                                        <?php if ($period_info): ?>
                                        <div class="mb-2 d-flex justify-content-between">
                                            <span>Period:</span>
                                            <span><?php echo htmlspecialchars($period_info['period_name'] ?? ''); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="mt-3 pt-2 border-top">
                                            <div class="d-flex justify-content-between">
                                                <span>Grand Total:</span>
                                                <span class="fw-bold text-primary fs-5">KES <?php echo number_format($grand_total, 2); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mb-4">
                        <h3 class="mb-4">
                            <i class="fas fa-boxes me-2"></i>
                            Quotation Items
                            <?php if ($is_hybrid): ?>
                                <span class="badge bg-info ms-2">Hybrid System</span>
                            <?php endif; ?>
                        </h3>
                        
                        <?php if (empty($all_items)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No items found in this quotation.
                                <?php if ($is_hybrid): ?>
                                    <div class="mt-2">
                                        <small>Hybrid quotations store items in a different format. Checking configuration...</small>
                                        <?php if (!empty($hybrid_config)): ?>
                                            <div class="mt-3">
                                                <strong>Hybrid Configuration Found:</strong>
                                                <div class="mt-2">
                                                    <pre class="bg-light p-3 rounded"><?php 
                                                        echo htmlspecialchars(json_encode($hybrid_config, JSON_PRETTY_PRINT)); 
                                                    ?></pre>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered items-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="45%">Description</th>
                                            <th width="10%">Units</th>
                                            <th width="10%" class="text-end">Quantity</th>
                                            <th width="15%" class="text-end">Unit Price (KES)</th>
                                            <th width="15%" class="text-end">Amount (KES)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $item_counter = 1;
                                        $display_subtotal = 0;
                                        foreach ($all_items as $item): 
                                            $quantity = floatval($item['quantity'] ?? 0);
                                            $rate = floatval($item['rate'] ?? 0);
                                            $amount = floatval($item['amount'] ?? ($quantity * $rate));
                                            $display_subtotal += $amount;
                                        ?>
                                        <tr>
                                            <td><?php echo $item_counter++; ?></td>
                                            <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($item['units'] ?? ''); ?></td>
                                            <td class="text-end"><?php echo number_format($quantity, 2); ?></td>
                                            <td class="text-end"><?php echo number_format($rate, 2); ?></td>
                                            <td class="text-end fw-bold"><?php echo number_format($amount, 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-secondary">
                                            <td colspan="5" class="text-end"><strong>Materials Subtotal:</strong></td>
                                            <td class="text-end fw-bold">KES <?php echo number_format($display_subtotal, 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <!-- Display hybrid configuration details if available -->
                            <?php if ($is_hybrid && !empty($hybrid_config) && isset($hybrid_config['varieties'])): ?>
                            <div class="card mt-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        <i class="fas fa-seedling me-2"></i>
                                        Hybrid Configuration Details
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($hybrid_config['varieties'] as $index => $variety): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card h-100">
                                                <div class="card-header py-2 bg-light">
                                                    <strong><?php echo htmlspecialchars($variety['crop_variety'] ?? $variety['variety_name'] ?? 'Variety ' . ($index + 1)); ?></strong>
                                                </div>
                                                <div class="card-body p-3">
                                                    <div class="mb-2">
                                                        <small class="text-muted">Crop Type:</small>
                                                        <div><?php echo htmlspecialchars(ucfirst($variety['crop_type'] ?? 'N/A')); ?></div>
                                                    </div>
                                                    <div class="mb-2">
                                                        <small class="text-muted">Irrigation Method:</small>
                                                        <div><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $variety['irrigation_method'] ?? 'N/A'))); ?></div>
                                                    </div>
                                                    <div class="mb-2">
                                                        <small class="text-muted">Area Percentage:</small>
                                                        <div>
                                                            <span class="badge bg-primary"><?php echo htmlspecialchars($variety['area_percentage'] ?? '0'); ?>%</span>
                                                        </div>
                                                    </div>
                                                    <?php if (isset($variety['parameters']) && is_array($variety['parameters']) && !empty($variety['parameters'][0])): 
                                                        $param = $variety['parameters'][0];
                                                    ?>
                                                    <div class="mt-3">
                                                        <small class="text-muted">Technical Parameters:</small>
                                                        <ul class="list-unstyled mb-0 small">
                                                            <?php if (!empty($param['row_spacing'])): ?>
                                                            <li>Row Spacing: <?php echo htmlspecialchars($param['row_spacing']); ?>m</li>
                                                            <?php endif; ?>
                                                            <?php if (!empty($param['plant_spacing'])): ?>
                                                            <li>Plant Spacing: <?php echo htmlspecialchars($param['plant_spacing']); ?>m</li>
                                                            <?php endif; ?>
                                                            <?php if (!empty($param['water_pressure'])): ?>
                                                            <li>Water Pressure: <?php echo htmlspecialchars($param['water_pressure']); ?> bar</li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                        <?php endif; ?>
                    </div>

                    <!-- Cost Summary -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-4">Cost Summary</h5>
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <tbody>
                                                <tr>
                                                    <td width="70%"><strong>Total Materials Cost:</strong></td>
                                                    <td width="30%" class="text-end fw-bold">
                                                        KES <?php echo number_format($total_material, 2); ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Labor Cost (<?php echo htmlspecialchars($labor_percentage); ?>%):</td>
                                                    <td class="text-end">
                                                        KES <?php echo number_format($labor_cost, 2); ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Subtotal:</td>
                                                    <td class="text-end">
                                                        KES <?php echo number_format($calculated_subtotal, 2); ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Discount (<?php echo htmlspecialchars($discount_percentage); ?>%):</td>
                                                    <td class="text-end text-danger">
                                                        - KES <?php echo number_format($discount_amount, 2); ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Taxable Amount:</td>
                                                    <td class="text-end">
                                                        KES <?php echo number_format($taxable, 2); ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>Tax (<?php echo htmlspecialchars($tax_rate); ?>%):</td>
                                                    <td class="text-end">
                                                        KES <?php echo number_format($tax_amount, 2); ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-primary text-white">
                                        <div class="card-body">
                                            <h6 class="card-title">Grand Total</h6>
                                            <div class="display-4 fw-bold mb-3">
                                                KES <?php echo number_format($grand_total, 2); ?>
                                            </div>
                                            <p class="mb-0 small">
                                                Valid for 30 days from quotation date
                                            </p>
                                            <p class="mb-0 small">
                                                <?php echo $is_hybrid ? 'Hybrid System' : ucfirst($quotation['irrigation_type'] ?? 'drip'); ?> Installation
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($is_incorrect && !empty($db_total_material)): ?>
                            <div class="mt-3 alert alert-warning">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> Database values differ from calculated values. Showing corrected calculations.
                                <?php if ($db_grand_total > 0): ?>
                                <div class="mt-2 small">
                                    Original grand total in database: KES <?php echo number_format($db_grand_total, 2); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Terms & Conditions</h5>
                        </div>
                        <div class="card-body">
                            <ol class="mb-0">
                                <li>This quotation is valid for 30 days from the date issued.</li>
                                <li>Prices are subject to change without prior notice.</li>
                                <li>Payment terms: 50% deposit upon acceptance, balance before installation.</li>
                                <li>Installation timeline: 2-4 weeks after receipt of deposit.</li>
                                <li>Warranty: 1 year on materials, 6 months on workmanship.</li>
                                <li>Any changes to the scope of work may affect the final price.</li>
                                <li>All taxes and levies are included in the quoted price.</li>
                                <?php if ($is_hybrid): ?>
                                <li>This hybrid system combines multiple irrigation methods as specified in the configuration.</li>
                                <li>Area percentages represent the portion of land allocated to each crop variety.</li>
                                <?php endif; ?>
                            </ol>
                        </div>
                    </div>
                </div> <!-- End of quotation-content -->

                <!-- Loading overlay for PDF generation -->
                <div id="pdfLoading" class="pdf-loading" style="display: none;">
                    <div class="spinner"></div>
                    <div>Generating PDF...</div>
                    <div class="small">Please wait while we create your quotation PDF</div>
                </div>

                <!-- Action Buttons -->
                <div class="mt-4 text-center no-print">
                    <div class="action-buttons d-flex justify-content-center gap-3">
                        <button onclick="window.print()" class="btn btn-primary">
                            <i class="fas fa-print me-2"></i> Print Quotation
                        </button>
                        <button onclick="downloadPDF()" class="btn btn-success">
                            <i class="fas fa-download me-2"></i> Download PDF
                        </button>
                        <a href="quotation.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Templates
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- JavaScript for PDF Generation -->
    <script>
        // Wait for libraries to load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize jsPDF
            const { jsPDF } = window.jspdf;
            
            // Global PDF download function
            window.downloadPDF = function() {
                // Show loading overlay
                const loadingOverlay = document.getElementById('pdfLoading');
                if (!loadingOverlay) {
                    console.error('PDF loading overlay not found');
                    alert('PDF loading overlay not found');
                    return;
                }
                loadingOverlay.style.display = 'flex';
                
                // Temporarily show PDF-only elements and hide no-print elements
                const pdfOnlyElements = document.querySelectorAll('.pdf-only');
                const noPrintElements = document.querySelectorAll('.no-print');
                
                // Store original display values
                pdfOnlyElements.forEach((el, index) => {
                    el.dataset.originalDisplay = el.style.display;
                    el.style.display = 'block';
                });
                
                noPrintElements.forEach((el, index) => {
                    el.dataset.originalDisplay = el.style.display;
                    el.style.display = 'none';
                });
                
                // Generate PDF from the entire quotation content
                setTimeout(() => {
                    const element = document.getElementById('quotation-content');
                    if (!element) {
                        console.error('Quotation content element not found');
                        loadingOverlay.style.display = 'none';
                        
                        // Restore original display values
                        pdfOnlyElements.forEach((el, index) => {
                            el.style.display = el.dataset.originalDisplay || '';
                        });
                        
                        noPrintElements.forEach((el, index) => {
                            el.style.display = el.dataset.originalDisplay || '';
                        });
                        
                        alert('Quotation content element not found');
                        return;
                    }
                    
                    html2canvas(element, {
                        scale: 2,
                        useCORS: true,
                        logging: false,
                        backgroundColor: '#ffffff',
                        width: element.scrollWidth,
                        height: element.scrollHeight
                    }).then(canvas => {
                        const imgData = canvas.toDataURL('image/png');
                        const pdf = new jsPDF('p', 'mm', 'a4');
                        const imgWidth = 210; // A4 width in mm
                        const pageHeight = 295; // A4 height in mm
                        const imgHeight = (canvas.height * imgWidth) / canvas.width;
                        
                        let heightLeft = imgHeight;
                        let position = 0;
                        
                        // Add first page
                        pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                        heightLeft -= pageHeight;
                        
                        // Add additional pages if content is too long
                        while (heightLeft > 0) {
                            position = heightLeft - imgHeight;
                            pdf.addPage();
                            pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                            heightLeft -= pageHeight;
                        }
                        
                        // Restore original display values
                        pdfOnlyElements.forEach((el, index) => {
                            el.style.display = el.dataset.originalDisplay || '';
                        });
                        
                        noPrintElements.forEach((el, index) => {
                            el.style.display = el.dataset.originalDisplay || '';
                        });
                        
                        // Save the PDF
                        const filename = 'Quotation_<?php echo htmlspecialchars($quotation['quotation_number'] ?? 'unnamed', ENT_QUOTES, 'UTF-8'); ?>.pdf';
                        pdf.save(filename);
                        
                        // Hide loading overlay
                        loadingOverlay.style.display = 'none';
                        
                        // Show success message
                        showAlert('PDF downloaded successfully!', 'success');
                    }).catch(error => {
                        console.error('PDF generation error:', error);
                        
                        // Restore original display values on error
                        pdfOnlyElements.forEach((el, index) => {
                            el.style.display = el.dataset.originalDisplay || '';
                        });
                        
                        noPrintElements.forEach((el, index) => {
                            el.style.display = el.dataset.originalDisplay || '';
                        });
                        
                        // Hide loading overlay
                        loadingOverlay.style.display = 'none';
                        
                        // Show error message
                        showAlert('Error generating PDF. Please try again or use the print feature.', 'danger');
                    });
                }, 500); // Small delay to ensure DOM updates
            };
            
            // Function to show alerts
            window.showAlert = function(message, type) {
                // Create alert element
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
                alertDiv.innerHTML = `
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Add to page
                const contentArea = document.querySelector('.content-area');
                if (contentArea) {
                    if (contentArea.firstChild) {
                        contentArea.insertBefore(alertDiv, contentArea.firstChild);
                    } else {
                        contentArea.appendChild(alertDiv);
                    }
                    
                    // Auto remove after 5 seconds
                    setTimeout(() => {
                        if (alertDiv.parentNode) {
                            alertDiv.remove();
                        }
                    }, 5000);
                }
            };
            
            // Debug info
            console.log('Quotation view page loaded');
            console.log('Quotation ID: <?php echo $quotation_id; ?>');
            console.log('Quotation Number: <?php echo htmlspecialchars($quotation["quotation_number"] ?? "N/A", ENT_QUOTES, "UTF-8"); ?>');
            console.log('Items Count: <?php echo count($all_items); ?>');
            console.log('Is Hybrid: <?php echo $is_hybrid ? "Yes" : "No"; ?>');
            console.log('Calculated Materials: <?php echo $total_material; ?>');
            console.log('DB Materials: <?php echo $db_total_material; ?>');
            
            // Ensure downloadPDF is defined
            console.log('downloadPDF function defined:', typeof window.downloadPDF);
        });
        
        // Auto-scroll for print
        window.addEventListener('beforeprint', function() {
            window.scrollTo(0, 0);
        });
        
        // Fallback in case DOMContentLoaded doesn't fire
        if (typeof window.downloadPDF === 'undefined') {
            window.downloadPDF = function() {
                alert('Please wait for the page to finish loading before downloading PDF.');
            };
        }
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>