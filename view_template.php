<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Database connection
$database = new Database();
$conn = $database->getConnection();

// Get current user
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Check if template ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Template ID is required.";
    header('Location: quotation.php');
    exit();
}

$template_id = intval($_GET['id']);

// Get template details
$stmt = $conn->prepare("SELECT * FROM irrigation_templates WHERE id = ? AND user_id = ? AND is_active = 1");
$stmt->bind_param("ii", $template_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$template = $result->fetch_assoc();
$stmt->close();

if (!$template) {
    $_SESSION['error_message'] = "Template not found or you don't have permission to view it.";
    header('Location: quotation.php');
    exit();
}

// Get all crop varieties for this template
$stmt = $conn->prepare("SELECT * FROM template_crop_varieties WHERE template_id = ? ORDER BY id");
$stmt->bind_param("i", $template_id);
$stmt->execute();
$result = $stmt->get_result();
$crop_varieties = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Decode JSON fields for each variety
foreach ($crop_varieties as &$variety) {
    $variety['parameters'] = json_decode($variety['parameters_json'], true) ?? [];
    $variety['items'] = json_decode($variety['items_json'], true) ?? [];
}

// Decode main template items if they exist
$main_items = json_decode($template['items_json'], true) ?? [];

// Get design parameters from the first variety (for backward compatibility)
$design_parameters = [];
if (!empty($crop_varieties[0]['parameters']) && is_array($crop_varieties[0]['parameters'])) {
    $first_params = $crop_varieties[0]['parameters'][0] ?? [];
    $design_parameters = [
        'row_spacing' => $first_params['row_spacing'] ?? 0.3,
        'plant_spacing' => $first_params['plant_spacing'] ?? 0.2,
        'water_pressure' => $first_params['water_pressure'] ?? 2.0,
        'notes' => $first_params['notes'] ?? ''
    ];
}

// Calculate totals from all varieties
$total_material = 0;
$all_items = [];

// Collect items from main template (if any)
foreach ($main_items as $item) {
    $item_amount = (float)($item['quantity'] ?? 0) * (float)($item['rate'] ?? 0);
    $item['amount'] = $item_amount;
    $all_items[] = $item;
    $total_material += $item_amount;
}

// Collect items from each crop variety
foreach ($crop_varieties as $variety) {
    if (!empty($variety['items']) && is_array($variety['items'])) {
        foreach ($variety['items'] as $item) {
            $item_amount = (float)($item['quantity'] ?? 0) * (float)($item['rate'] ?? 0);
            $item['amount'] = $item_amount;
            // Add variety prefix to description
            $item['description'] = "[{$variety['crop_variety']}] " . ($item['description'] ?? 'Item');
            $all_items[] = $item;
            $total_material += $item_amount;
        }
    }
}

// Calculate cost summary
$labor_percentage = floatval($template['labor_percentage'] ?? 35);
$discount_percentage = floatval($template['discount_percentage'] ?? 5);
$tax_rate = floatval($template['tax_rate'] ?? 16);

$labor_cost = $total_material * ($labor_percentage / 100);
$subtotal = $total_material + $labor_cost;
$discount_amount = $subtotal * ($discount_percentage / 100);
$taxable_amount = $subtotal - $discount_amount;
$tax_amount = $taxable_amount * ($tax_rate / 100);
$grand_total = $taxable_amount + $tax_amount;

// Get crop variety name (for backward compatibility)
function getCropVarietyName($crop_varieties) {
    if (empty($crop_varieties)) {
        return 'N/A';
    }
    
    $variety_names = [];
    foreach ($crop_varieties as $variety) {
        if (!empty($variety['crop_variety'])) {
            $variety_names[] = $variety['crop_variety'];
        }
    }
    
    return !empty($variety_names) ? implode(', ', $variety_names) : 'N/A';
}

// Helper function to safely format numbers
function formatNumber($number, $decimals = 2) {
    if ($number === null || $number === '') {
        return number_format(0, $decimals);
    }
    return number_format(floatval($number), $decimals);
}

include 'nav_bar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Template | Vinmel Irrigation</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
    <style>
        .template-header {
            background: linear-gradient(135deg, var(--dark-section), var(--primary-blue-dark));
            color: var(--white);
            padding: var(--space-xl);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-xl);
        }
        
        .template-icon-large {
            font-size: 3rem;
            margin-bottom: var(--space-md);
            color: var(--white);
        }
        
        .template-badge {
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .hybrid-badge {
            background: linear-gradient(135deg, #7b1fa2, #9c27b0);
        }
        
        .detail-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: var(--space-lg);
            margin-bottom: var(--space-md);
        }
        
        .detail-label {
            color: var(--medium-text);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .detail-value {
            color: var(--dark-text);
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .summary-box {
            background: var(--light-bg);
            border-radius: var(--radius-md);
            padding: var(--space-lg);
            border: 1px solid var(--border-color);
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-total {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary-blue);
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
        
        .variety-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        
        .variety-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .variety-title {
            font-weight: 600;
            color: #495057;
        }
        
        .variety-number {
            display: inline-block;
            background: #6c757d;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            text-align: center;
            line-height: 28px;
            margin-right: 10px;
            font-size: 14px;
        }
        
        .variety-badge {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-right: 8px;
            margin-bottom: 8px;
            border: 1px solid #c8e6c9;
        }
        
        .irrigation-badge {
            background: #e3f2fd;
            color: #1565c0;
            border-color: #bbdefb;
        }
        
        .parameter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            margin-bottom: 20px;
        }
        
        .parameter-card {
            background: white;
            border-radius: 8px;
            padding: 12px;
            border-left: 4px solid #667eea;
            border: 1px solid #e9ecef;
        }
        
        .parameter-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .parameter-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white !important;
                color: black !important;
            }
            
            .detail-card, .summary-box, .variety-section {
                border: 1px solid #ddd !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <header class="top-header">
            <div class="header-content">
                <div class="header-left">
                    <button class="mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <a href="dashboard.php" class="logo">
                        <div class="logo-icon">V</div>
                        <span class="logo-text">Vinmel Irrigation</span>
                    </a>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                            <div class="user-details">
                                <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                                <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-area">
                <!-- Back Button -->
                <div class="mb-4 no-print">
                    <a href="quotation.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Templates
                    </a>
                </div>

                <!-- Template Header -->
                <div class="template-header">
                    <div class="row align-items-center">
                        <div class="col-md-2 text-center">
                            <div class="template-icon-large">
                                <?php 
                                $is_hybrid = ($template['is_hybrid'] ?? 0) == 1;
                                if ($is_hybrid): ?>
                                    <i class="fas fa-random"></i>
                                <?php elseif ($template['irrigation_type'] == 'drip'): ?>
                                    <i class="fas fa-tint"></i>
                                <?php elseif ($template['irrigation_type'] == 'sprinkler'): ?>
                                    <i class="fas fa-shower"></i>
                                <?php else: ?>
                                    <i class="fas fa-cloud-rain"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <h1 class="mb-2"><?php echo htmlspecialchars($template['template_name']); ?></h1>
                            <p class="mb-3"><?php echo htmlspecialchars($template['description'] ?? 'No description provided'); ?></p>
                            <div class="d-flex gap-2">
                                <span class="template-badge"><?php echo strtoupper($template['template_type']); ?> TEMPLATE</span>
                                <?php if ($is_hybrid): ?>
                                    <span class="template-badge hybrid-badge">HYBRID SYSTEM</span>
                                <?php else: ?>
                                    <span class="template-badge"><?php echo strtoupper($template['irrigation_type']); ?> IRRIGATION</span>
                                <?php endif; ?>
                                <span class="template-badge"><?php echo count($crop_varieties); ?> VARIET<?php echo count($crop_varieties) != 1 ? 'IES' : 'Y'; ?></span>
                                <span class="template-badge">CREATED: <?php echo date('M d, Y', strtotime($template['created_at'])); ?></span>
                            </div>
                        </div>
                        <div class="col-md-2 text-end no-print">
                            <div class="action-buttons">
                                <button onclick="window.print()" class="btn btn-primary">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <a href="quotation.php?delete_template=<?php echo $template['id']; ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this template?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Template Details -->
                <div class="row">
                    <!-- Left Column: Basic Info -->
                    <div class="col-md-4">
                        <div class="detail-card">
                            <h5 class="mb-4"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                            <div class="mb-3">
                                <div class="detail-label">Project Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($template['project_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="detail-label">Customer Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($template['customer_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="detail-label">Location</div>
                                <div class="detail-value"><?php echo htmlspecialchars($template['location'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="detail-label">Created By</div>
                                <div class="detail-value"><?php echo htmlspecialchars($user['name']); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="detail-label">Last Updated</div>
                                <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($template['updated_at'] ?? $template['created_at'])); ?></div>
                            </div>
                        </div>

                        <!-- Design Parameters -->
                        <div class="detail-card">
                            <h5 class="mb-4"><i class="fas fa-ruler-combined me-2"></i>Design Parameters</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Land Size</div>
                                    <div class="detail-value"><?php echo formatNumber($template['land_size']); ?> <?php echo $template['land_unit']; ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Crop Type</div>
                                    <div class="detail-value"><?php echo ucfirst($template['crop_type'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Crop Varieties</div>
                                    <div class="detail-value"><?php echo count($crop_varieties); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Irrigation Type</div>
                                    <div class="detail-value">
                                        <?php 
                                        echo ucfirst($template['irrigation_type'] ?? 'drip');
                                        if ($is_hybrid) echo ' (Hybrid)';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Show parameters if available -->
                            <?php if (!empty($design_parameters) && ($design_parameters['row_spacing'] ?? 0) > 0): ?>
                            <div class="parameter-grid">
                                <div class="parameter-card">
                                    <div class="parameter-label">Row Spacing</div>
                                    <div class="parameter-value"><?php echo formatNumber($design_parameters['row_spacing'], 2); ?> m</div>
                                </div>
                                <div class="parameter-card">
                                    <div class="parameter-label">Plant Spacing</div>
                                    <div class="parameter-value"><?php echo formatNumber($design_parameters['plant_spacing'], 2); ?> m</div>
                                </div>
                                <div class="parameter-card">
                                    <div class="parameter-label">Water Pressure</div>
                                    <div class="parameter-value"><?php echo formatNumber($design_parameters['water_pressure'], 1); ?> Bar</div>
                                </div>
                                <?php if (!empty($design_parameters['notes'])): ?>
                                <div class="parameter-card" style="grid-column: 1 / -1;">
                                    <div class="parameter-label">Notes</div>
                                    <div class="parameter-value"><?php echo htmlspecialchars($design_parameters['notes']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($template['system_efficiency'])): ?>
                            <div class="mb-3">
                                <div class="detail-label">System Efficiency</div>
                                <div class="detail-value"><?php echo formatNumber($template['system_efficiency'], 1); ?>%</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Crop Varieties Summary -->
                        <?php if (!empty($crop_varieties)): ?>
                        <div class="detail-card">
                            <h5 class="mb-4"><i class="fas fa-seedling me-2"></i>Crop Varieties</h5>
                            <?php foreach ($crop_varieties as $index => $variety): ?>
                            <div class="mb-3 pb-3 <?php echo $index < count($crop_varieties) - 1 ? 'border-bottom' : ''; ?>">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="variety-number"><?php echo $index + 1; ?></span>
                                    <h6 class="mb-0"><?php echo htmlspecialchars($variety['crop_variety'] ?? 'Variety ' . ($index + 1)); ?></h6>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="variety-badge"><?php echo ucfirst($variety['crop_type'] ?? 'N/A'); ?></span>
                                    <span class="variety-badge irrigation-badge"><?php echo ucfirst($variety['irrigation_method'] ?? 'drip'); ?></span>
                                    <?php if (!empty($variety['area_percentage'])): ?>
                                    <span class="variety-badge" style="background: #fff3e0; color: #ef6c00; border-color: #ffcc80;">
                                        <?php echo formatNumber($variety['area_percentage'], 1); ?>% area
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($variety['items']) && is_array($variety['items'])): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-box me-1"></i>
                                    <?php echo count($variety['items']); ?> item(s)
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Items and Summary -->
                    <div class="col-md-8">
                        <?php if (!empty($crop_varieties)): ?>
                            <!-- Display items by variety for hybrid systems -->
                            <?php foreach ($crop_varieties as $index => $variety): ?>
                                <?php if (!empty($variety['items']) && is_array($variety['items'])): ?>
                                <div class="variety-section mb-4">
                                    <div class="variety-header">
                                        <div>
                                            <span class="variety-number"><?php echo $index + 1; ?></span>
                                            <span class="variety-title"><?php echo htmlspecialchars($variety['crop_variety'] ?? 'Variety ' . ($index + 1)); ?> - Materials</span>
                                        </div>
                                        <div>
                                            <span class="variety-badge"><?php echo ucfirst($variety['crop_type'] ?? 'N/A'); ?></span>
                                            <span class="variety-badge irrigation-badge"><?php echo ucfirst($variety['irrigation_method'] ?? 'drip'); ?></span>
                                        </div>
                                    </div>
                                    
                                    <!-- Variety Parameters -->
                                    <?php if (!empty($variety['parameters'])): ?>
                                    <div class="mb-4">
                                        <h6 class="mb-3">Agronomic Parameters</h6>
                                        <div class="parameter-grid">
                                            <?php foreach ($variety['parameters'] as $param_index => $param): ?>
                                            <div class="parameter-card">
                                                <div class="parameter-label">Configuration <?php echo $param_index + 1; ?></div>
                                                <div class="small">
                                                    <div>Row: <?php echo formatNumber($param['row_spacing'] ?? 0.3, 2); ?>m</div>
                                                    <div>Plant: <?php echo formatNumber($param['plant_spacing'] ?? 0.2, 2); ?>m</div>
                                                    <div>Pressure: <?php echo formatNumber($param['water_pressure'] ?? 2.0, 1); ?>Bar</div>
                                                    <?php if (!empty($param['notes'])): ?>
                                                    <div class="text-muted mt-1"><small><?php echo htmlspecialchars($param['notes']); ?></small></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Variety Items Table -->
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Description</th>
                                                    <th>Units</th>
                                                    <th class="text-end">Quantity</th>
                                                    <th class="text-end">Rate (KES)</th>
                                                    <th class="text-end">Amount (KES)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $variety_total = 0;
                                                foreach ($variety['items'] as $item_index => $item): 
                                                    $item_amount = (float)($item['quantity'] ?? 0) * (float)($item['rate'] ?? 0);
                                                    $variety_total += $item_amount;
                                                ?>
                                                <tr>
                                                    <td><?php echo $item_index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($item['units'] ?? ''); ?></td>
                                                    <td class="text-end"><?php echo formatNumber($item['quantity'] ?? 0); ?></td>
                                                    <td class="text-end"><?php echo formatNumber($item['rate'] ?? 0); ?></td>
                                                    <td class="text-end fw-bold"><?php echo formatNumber($item_amount); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="5" class="text-end fw-bold">Variety Total:</td>
                                                    <td class="text-end fw-bold text-primary">KES <?php echo formatNumber($variety_total); ?></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Main Template Items (if any, non-hybrid) -->
                        <?php if (empty($crop_varieties) && !empty($main_items)): ?>
                        <div class="detail-card mb-4">
                            <h5 class="mb-4"><i class="fas fa-list me-2"></i>Template Items (<?php echo count($main_items); ?> items)</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Description</th>
                                            <th>Units</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Rate (KES)</th>
                                            <th class="text-end">Amount (KES)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($main_items as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($item['units'] ?? ''); ?></td>
                                            <td class="text-end"><?php echo formatNumber($item['quantity'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo formatNumber($item['rate'] ?? 0); ?></td>
                                            <td class="text-end fw-bold"><?php echo formatNumber($item['amount'] ?? 0); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="5" class="text-end fw-bold">Total Material Cost:</td>
                                            <td class="text-end fw-bold text-primary">KES <?php echo formatNumber($total_material); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Cost Summary -->
                        <div class="summary-box">
                            <h5 class="mb-4"><i class="fas fa-calculator me-2"></i>Cost Summary</h5>
                            <div class="summary-item">
                                <span>Material Cost:</span>
                                <span class="fw-bold">KES <?php echo formatNumber($total_material); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Labor Cost (<?php echo $labor_percentage; ?>%):</span>
                                <span class="fw-bold">KES <?php echo formatNumber($labor_cost); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Subtotal:</span>
                                <span class="fw-bold">KES <?php echo formatNumber($subtotal); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Discount (<?php echo $discount_percentage; ?>%):</span>
                                <span class="fw-bold text-danger">- KES <?php echo formatNumber($discount_amount); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Taxable Amount:</span>
                                <span class="fw-bold">KES <?php echo formatNumber($taxable_amount); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Tax (<?php echo $tax_rate; ?>%):</span>
                                <span class="fw-bold">KES <?php echo formatNumber($tax_amount); ?></span>
                            </div>
                            <div class="summary-item summary-total">
                                <span>GRAND TOTAL:</span>
                                <span>KES <?php echo formatNumber($grand_total); ?></span>
                            </div>
                        </div>

                        <!-- Use Template Button -->
                        <div class="mt-4 text-center no-print">
                            <button class="btn btn-primary btn-lg" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#useTemplateModal"
                                    data-template-id="<?php echo $template['id']; ?>"
                                    data-template-name="<?php echo htmlspecialchars($template['template_name']); ?>"
                                    data-is-hybrid="<?php echo $is_hybrid ? '1' : '0'; ?>"
                                    data-variety-count="<?php echo count($crop_varieties); ?>">
                                <i class="fas fa-play me-2"></i> Use This Template
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Use Template Modal -->
    <div class="modal fade" id="useTemplateModal" tabindex="-1" aria-labelledby="useTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="useTemplateModalLabel">Generate Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="quotation.php" id="useTemplateForm">
                    <div class="modal-body">
                        <input type="hidden" name="template_id" id="useTemplateId">
                        <input type="hidden" name="generate_from_template" value="1">
                        <input type="hidden" name="save_quotation" value="1">
                        
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Generating quotation from: <strong id="useTemplateName"></strong>
                            <div id="hybridInfo" style="display: none;"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Quotation Number</label>
                            <input type="text" class="form-control" name="quotation_number" 
                                   value="QTN-<?php echo date('Ymd-His'); ?>" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Project Name *</label>
                            <input type="text" class="form-control" name="project_name" required
                                   placeholder="Enter project name" value="<?php echo htmlspecialchars($template['project_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   placeholder="Optional" value="<?php echo htmlspecialchars($template['customer_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" 
                                   placeholder="e.g., Nyandarua-Murungaru" value="<?php echo htmlspecialchars($template['location'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Land Size (<?php echo $template['land_unit']; ?>)</label>
                            <input type="number" class="form-control" name="land_size" 
                                   step="0.01" min="0.01" value="<?php echo $template['land_size']; ?>">
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            The quotation will be automatically saved to your quotations list.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-invoice-dollar me-2"></i> Generate Quotation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Use Template Modal functionality
        const useTemplateModal = document.getElementById('useTemplateModal');
        if (useTemplateModal) {
            useTemplateModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const templateId = button.getAttribute('data-template-id');
                const templateName = button.getAttribute('data-template-name');
                const isHybrid = button.getAttribute('data-is-hybrid') === '1';
                const varietyCount = button.getAttribute('data-variety-count') || 0;
                
                document.getElementById('useTemplateId').value = templateId;
                document.getElementById('useTemplateName').textContent = templateName;
                
                const hybridInfoDiv = document.getElementById('hybridInfo');
                if (isHybrid && hybridInfoDiv) {
                    hybridInfoDiv.innerHTML = `
                        <div class="mt-2 small">
                            <strong>Hybrid System:</strong> ${varietyCount} crop variet${varietyCount != 1 ? 'ies' : 'y'}
                        </div>
                    `;
                    hybridInfoDiv.style.display = 'block';
                } else if (hybridInfoDiv) {
                    hybridInfoDiv.style.display = 'none';
                }
                
                // Generate unique quotation number
                const now = new Date();
                const dateStr = now.toISOString().slice(0,10).replace(/-/g, '');
                const timeStr = now.getTime().toString().slice(-6);
                
                const quotationInput = document.querySelector('#useTemplateForm input[name="quotation_number"]');
                if (quotationInput) {
                    quotationInput.value = `QTN-${dateStr}-${timeStr}`;
                }
                
                // Auto-focus on project name
                setTimeout(() => {
                    const projectNameInput = document.querySelector('#useTemplateForm input[name="project_name"]');
                    if (projectNameInput) projectNameInput.focus();
                }, 500);
            });
        }
        
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.toggle('mobile-open');
            }
        });
        
        // Form submission loading
        document.getElementById('useTemplateForm')?.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
                submitBtn.disabled = true;
            }
        });
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>