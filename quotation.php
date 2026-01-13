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

// Get active period
$stmt = $conn->prepare("SELECT * FROM time_periods WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$active_period = $result->fetch_assoc();
$stmt->close();

// Function to save quotation to database
function saveQuotationToDatabase($quotation, $user_id, $period_id, $conn) {
    try {
        // Create quotations table if it doesn't exist
        $create_table = "
        CREATE TABLE IF NOT EXISTS irrigation_quotations (
            id INT(11) PRIMARY KEY AUTO_INCREMENT,
            quotation_number VARCHAR(50) UNIQUE,
            project_name VARCHAR(255),
            customer_name VARCHAR(255),
            location VARCHAR(255),
            land_size DECIMAL(10,2),
            land_unit VARCHAR(20),
            crop_type VARCHAR(100),
            irrigation_type VARCHAR(50),
            total_material DECIMAL(15,2),
            labor_cost DECIMAL(15,2),
            discount_amount DECIMAL(15,2),
            tax_amount DECIMAL(15,2),
            grand_total DECIMAL(15,2),
            items_json TEXT,
            template_id INT(11),
            user_id INT(11),
            period_id INT(11),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (period_id) REFERENCES time_periods(id)
        )";
        
        $conn->query($create_table);
        
        // Insert quotation
        $stmt = $conn->prepare("
            INSERT INTO irrigation_quotations 
            (quotation_number, project_name, customer_name, location, land_size, land_unit, 
             crop_type, irrigation_type, total_material, labor_cost, discount_amount, 
             tax_amount, grand_total, items_json, template_id, user_id, period_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $items_json = json_encode($quotation['items'] ?? []);
        
        $stmt->bind_param(
            "ssssdsssddddssiii",
            $quotation['quotation_number'],
            $quotation['project_name'],
            $quotation['customer_name'],
            $quotation['location'],
            $quotation['land_size'],
            $quotation['land_unit'],
            $quotation['crop_type'],
            $quotation['irrigation_type'],
            $quotation['total_material'] ?? 0,
            $quotation['labor_cost'] ?? 0,
            $quotation['discount_amount'] ?? 0,
            $quotation['tax_amount'] ?? 0,
            $quotation['grand_total'] ?? 0,
            $items_json,
            $quotation['template_id'] ?? null,
            $user_id,
            $period_id
        );
        
        $stmt->execute();
        $quotation_id = $conn->insert_id;
        $stmt->close();
        
        return $quotation_id;
    } catch (Exception $e) {
        error_log("Error saving quotation: " . $e->getMessage());
        return false;
    }
}

// Handle template creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_template'])) {
    $template_data = $_POST;
    $template_id = saveTemplateToDatabase($template_data, $user_id, $conn);
    
    if ($template_id) {
        $_SESSION['success_message'] = "Template created successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to create template.";
    }
    header("Location: irrigation_quotations.php");
    exit();
}

// Handle quotation generation from template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_from_template'])) {
    $template_id = $_POST['template_id'];
    $quotation_data = $_POST;
    $quotation = generateQuotationFromTemplate($template_id, $quotation_data, $conn);
    
    if (isset($_POST['save_quotation'])) {
        $quotation['template_id'] = $template_id;
        saveQuotationToDatabase($quotation, $user_id, $active_period['id'], $conn);
        $_SESSION['success_message'] = "Quotation generated and saved!";
        header("Location: irrigation_quotations.php");
        exit();
    }
}

// Handle template deletion
if (isset($_GET['delete_template'])) {
    $template_id = $_GET['delete_template'];
    deleteTemplate($template_id, $conn);
    $_SESSION['success_message'] = "Template deleted successfully!";
    header("Location: irrigation_quotations.php");
    exit();
}

// Function to save template to database
function saveTemplateToDatabase($data, $user_id, $conn) {
    try{
        
        // Prepare items JSON
        $items = [];
        if (isset($data['item_description'])) {
            $count = count($data['item_description']);
            for ($i = 0; $i < $count; $i++) {
                if (!empty($data['item_description'][$i])) {
                    $items[] = [
                        'description' => $data['item_description'][$i],
                        'units' => $data['item_units'][$i],
                        'quantity' => $data['item_quantity'][$i],
                        'rate' => $data['item_rate'][$i],
                        'amount' => $data['item_quantity'][$i] * $data['item_rate'][$i]
                    ];
                }
            }
        }
        $items_json = json_encode($items);
        
        // Prepare design summary
        $design_summary = json_encode([
            'project_name' => $data['project_name'] ?? '',
            'land_size' => $data['land_size'] ?? 0,
            'land_unit' => $data['land_unit'] ?? 'acres',
            'crop_type' => $data['crop_type'] ?? '',
            'irrigation_type' => $data['irrigation_type'] ?? 'drip'
        ]);
        
        // Insert template
        $stmt = $conn->prepare("
            INSERT INTO irrigation_quotations
            (template_name, template_type, description, project_name, customer_name, location, 
             land_size, land_unit, crop_type, crop_variety, irrigation_type, row_spacing, 
             plant_spacing, water_pressure, system_efficiency, labor_percentage, discount_percentage, 
             tax_rate, items_json, design_summary, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "ssssssdssssddddddddssi",
            $data['template_name'],
            $data['template_type'] ?? 'standard',
            $data['description'] ?? '',
            $data['project_name'] ?? '',
            $data['customer_name'] ?? '',
            $data['location'] ?? '',
            $data['land_size'] ?? 0,
            $data['land_unit'] ?? 'acres',
            $data['crop_type'] ?? '',
            $data['crop_variety'] ?? '',
            $data['irrigation_type'] ?? 'drip',
            $data['row_spacing'] ?? 0.3,
            $data['plant_spacing'] ?? 0.2,
            $data['water_pressure'] ?? 2.0,
            $data['system_efficiency'] ?? 85,
            $data['labor_percentage'] ?? 35,
            $data['discount_percentage'] ?? 5,
            $data['tax_rate'] ?? 16,
            $items_json,
            $design_summary,
            $user_id
        );
        
        $stmt->execute();
        $template_id = $conn->insert_id;
        $stmt->close();
        
        return $template_id;
    } catch (Exception $e) {
        error_log("Error saving template: " . $e->getMessage());
        return false;
    }
}

// Function to get all templates
function getAllTemplates($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT * FROM irrigation_quotations
        WHERE user_id = ? AND is_active = 1 
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $templates = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $templates;
}

// Function to get template by ID
function getTemplateById($template_id, $conn) {
    $stmt = $conn->prepare("SELECT * FROM irrigation_quotations WHERE id = ?");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = $result->fetch_assoc();
    $stmt->close();
    
    if ($template) {
        $template['items'] = json_decode($template['items_json'], true) ?? [];
        $template['design_summary'] = json_decode($template['design_summary'], true) ?? [];
    }
    
    return $template;
}

// Function to generate quotation from template
function generateQuotationFromTemplate($template_id, $data, $conn) {
    $template = getTemplateById($template_id, $conn);
    
    if (!$template) {
        return null;
    }
    
    // Use template data, override with any custom data from form
    $quotation = [
        'quotation_number' => $data['quotation_number'] ?? 'QTN-' . date('Ymd-His'),
        'project_name' => $data['project_name'] ?? $template['project_name'],
        'customer_name' => $data['customer_name'] ?? $template['customer_name'],
        'location' => $data['location'] ?? $template['location'],
        'land_size' => floatval($data['land_size'] ?? $template['land_size']),
        'land_unit' => $data['land_unit'] ?? $template['land_unit'],
        'crop_type' => $data['crop_type'] ?? $template['crop_type'],
        'crop_variety' => $data['crop_variety'] ?? $template['crop_variety'],
        'irrigation_type' => $data['irrigation_type'] ?? $template['irrigation_type'],
        'row_spacing' => floatval($data['row_spacing'] ?? $template['row_spacing']),
        'plant_spacing' => floatval($data['plant_spacing'] ?? $template['plant_spacing']),
        'water_pressure' => floatval($data['water_pressure'] ?? $template['water_pressure']),
        'system_efficiency' => floatval($data['system_efficiency'] ?? $template['system_efficiency']),
        'labor_percentage' => floatval($data['labor_percentage'] ?? $template['labor_percentage']),
        'discount_percentage' => floatval($data['discount_percentage'] ?? $template['discount_percentage']),
        'tax_rate' => floatval($data['tax_rate'] ?? $template['tax_rate']),
        'items' => [],
        'template_name' => $template['template_name']
    ];
    
    // Scale items based on land size if needed
    $scale_factor = 1;
    if (isset($data['land_size']) && $template['land_size'] > 0) {
        $scale_factor = floatval($data['land_size']) / $template['land_size'];
    }
    
    // Process template items
    foreach ($template['items'] as $item) {
        $scaled_item = $item;
        if ($scale_factor != 1) {
            $scaled_item['quantity'] = ceil($item['quantity'] * $scale_factor);
            $scaled_item['amount'] = $scaled_item['quantity'] * $item['rate'];
        }
        $quotation['items'][] = $scaled_item;
    }
    
    // Calculate totals
    $total_material = array_sum(array_column($quotation['items'], 'amount'));
    $labor_cost = $total_material * ($quotation['labor_percentage'] / 100);
    $subtotal = $total_material + $labor_cost;
    $discount_amount = $subtotal * ($quotation['discount_percentage'] / 100);
    $taxable_amount = $subtotal - $discount_amount;
    $tax_amount = $taxable_amount * ($quotation['tax_rate'] / 100);
    $grand_total = $taxable_amount + $tax_amount;
    
    // Add calculated costs
    $quotation['total_material'] = $total_material;
    $quotation['labor_cost'] = $labor_cost;
    $quotation['discount_amount'] = $discount_amount;
    $quotation['tax_amount'] = $tax_amount;
    $quotation['grand_total'] = $grand_total;
    
    return $quotation;
}

// Function to delete template
function deleteTemplate($template_id, $conn) {
    $stmt = $conn->prepare("UPDATE irrigation_templates SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $stmt->close();
}

// Get all templates for the user
$templates = getAllTemplates($user_id, $conn);

// Get recent quotations
function getRecentQuotations($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT q.*, t.template_name 
        FROM irrigation_quotations q
        LEFT JOIN irrigation_quotations t ON q.template_id = t.id
        WHERE q.user_id = ? 
        ORDER BY q.created_at DESC 
        LIMIT 10
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $quotations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $quotations;
}

$recent_quotations = getRecentQuotations($user_id, $conn);

include 'nav_bar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Irrigation Quotation Templates | Vinmel Irrigation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Fix for modal positioning */
        .modal {
            z-index: 1060 !important;
            display: none !important;
        }
        
        .modal.show {
            display: block !important;
        }
        
        .modal-backdrop {
            z-index: 1050 !important;
        }
        
        .modal-dialog {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1061;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-xl {
            max-width: 1200px;
            width: 90%;
        }
        
        .modal-content {
            max-height: 90vh;
            overflow-y: auto;
        }
        
        /* Template Cards */
        .template-card {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            height: 100%;
            transition: var(--transition-normal);
            cursor: pointer;
            position: relative;
            background: white;
            min-height: 280px;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-sm);
        }
        
        .template-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-blue);
        }
        
        .template-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-blue-light), var(--primary-blue));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--space-md);
        }
        
        .template-icon i {
            font-size: 1.5rem;
            color: white;
        }
        
        .template-type-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 1;
        }
        
        .template-type-drip {
            background: rgba(41, 171, 135, 0.1);
            color: var(--primary-blue);
            border: 1px solid rgba(41, 171, 135, 0.3);
        }
        
        .template-type-sprinkler {
            background: rgba(13, 110, 253, 0.1);
            color: var(--info-color);
            border: 1px solid rgba(13, 110, 253, 0.3);
        }
        
        .template-type-overhead {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .template-actions {
            margin-top: auto;
            padding-top: var(--space-md);
            display: flex;
            gap: 0.5rem;
            border-top: 1px solid var(--border-color);
        }
        
        .action-btn {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: var(--light-bg);
            color: var(--medium-text);
            transition: var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.875rem;
        }
        
        .action-btn:hover {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            text-decoration: none;
        }
        
        .action-btn.delete-btn:hover {
            background: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        /* Items Table in Modal */
        .items-table {
            font-size: 0.875rem;
        }
        
        .items-table th {
            background: var(--light-bg);
            font-weight: 600;
            padding: 0.75rem;
        }
        
        .items-table td {
            padding: 0.75rem;
            vertical-align: middle;
        }
        
        .add-item-btn {
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .remove-item-btn {
            color: var(--danger-color);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
        }
        
        .tab-content {
            padding: var(--space-lg) 0;
        }
        
        .nav-tabs {
            border-bottom: 2px solid var(--border-color);
        }
        
        .nav-tabs .nav-link {
            border: 1px solid transparent;
            border-radius: var(--radius-md) var(--radius-md) 0 0;
            padding: 0.75rem 1.5rem;
            color: var(--medium-text);
            font-weight: 500;
            margin-bottom: -2px;
        }
        
        .nav-tabs .nav-link.active {
            background: white;
            border-color: var(--border-color) var(--border-color) white;
            color: var(--primary-blue);
            border-bottom: 3px solid var(--primary-blue);
        }
        
        .empty-state {
            text-align: center;
            padding: var(--space-xl);
            color: var(--medium-text);
            background: white;
            border-radius: var(--radius-lg);
            border: 2px dashed var(--border-color);
            margin: var(--space-lg) 0;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: var(--space-md);
            color: var(--border-color);
        }
        
        /* Content area spacing */
        .content-area {
            padding: var(--space-lg);
            position: relative;
            z-index: 1;
        }
        
        /* Dashboard header */
        .dashboard-header {
            background: white;
            padding: var(--space-lg);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-lg);
            box-shadow: var(--shadow-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Alert positioning */
        .alert {
            position: relative;
            z-index: 1;
        }
        
        /* Recent quotations table */
        .table th {
            background: var(--light-bg);
            font-weight: 600;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .modal-dialog {
                width: 95%;
                margin: 0;
            }
            
            .dashboard-header {
                flex-direction: column;
                gap: var(--space-md);
                text-align: center;
            }
            
            .template-actions {
                flex-direction: column;
            }
        }
        
        /* Animation for modal */
        .modal.fade .modal-dialog {
            transform: translate(-50%, -60%);
            transition: transform 0.3s ease-out;
        }
        
        .modal.show .modal-dialog {
            transform: translate(-50%, -50%);
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
                    <?php if ($active_period): ?>
                    <div class="period-status-container">
                        <div class="period-indicator period-active">
                            <div class="period-main">
                                <span class="period-name"><?php echo htmlspecialchars($active_period['period_name']); ?></span>
                                <span class="status-badge active">Active</span>
                            </div>
                            <div class="period-dates">
                                <small><?php echo date('M d', strtotime($active_period['start_date'])); ?> - <?php echo date('M d, Y', strtotime($active_period['end_date'])); ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="user-menu">
                        <div class="notification-bell">
                            <i class="fas fa-bell"></i>
                            <span class="notification-badge">3</span>
                        </div>
                        
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
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div>
                        <h2><i class="fas fa-tint"></i> Irrigation Quotation Templates</h2>
                        <p class="text-muted">Create, manage, and reuse irrigation system templates</p>
                    </div>
                    <div class="text-end">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTemplateModal">
                            <i class="fas fa-plus"></i> New Template
                        </button>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['success_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['error_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <!-- Templates Grid -->
                <div class="row">
                    <?php if (empty($templates)): ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h4>No Templates Yet</h4>
                            <p>Create your first irrigation template to get started</p>
                            <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#newTemplateModal">
                                <i class="fas fa-plus"></i> Create First Template
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                        <?php foreach ($templates as $template): 
                            $items = [];
                            if (isset($template['items_json'])) {
                                $items = json_decode($template['items_json'], true) ?? [];
                            }
                            
                            // Calculate total from items
                            $total = 0;
                            foreach ($items as $item) {
                                $total += ($item['quantity'] ?? 0) * ($item['rate'] ?? 0);
                            }
                            
                            // Get irrigation type for styling
                            $irrigation_type = $template['irrigation_type'] ?? 'drip';
                        ?>
                        <div class="col-md-4 mb-4">
                            <div class="template-card">
                                <span class="template-type-badge template-type-<?php echo $irrigation_type; ?>">
                                    <?php echo strtoupper($irrigation_type); ?>
                                </span>
                                
                                <div class="template-icon">
                                    <?php if ($irrigation_type == 'drip'): ?>
                                        <i class="fas fa-tint"></i>
                                    <?php elseif ($irrigation_type == 'sprinkler'): ?>
                                        <i class="fas fa-shower"></i>
                                    <?php else: ?>
                                        <i class="fas fa-cloud-rain"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <h5 class="mb-2"><?php echo htmlspecialchars($template['template_name']); ?></h5>
                                <p class="text-muted small mb-3"><?php echo htmlspecialchars($template['description'] ?? 'No description'); ?></p>
                                
                                <div class="template-details mt-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Area:</span>
                                        <span><?php echo $template['land_size'] ?? 0; ?> <?php echo $template['land_unit'] ?? 'acres'; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Crop:</span>
                                        <span><?php echo ucfirst($template['crop_type'] ?? 'vegetables'); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Items:</span>
                                        <span><?php echo count($items); ?> items</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-3">
                                        <span class="text-muted">Est. Total:</span>
                                        <span class="fw-bold">KES <?php echo number_format($total); ?></span>
                                    </div>
                                </div>
                                
                                <div class="template-actions">
                                    <button class="action-btn" onclick="viewTemplate(<?php echo $template['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="action-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#useTemplateModal"
                                            data-template-id="<?php echo $template['id']; ?>"
                                            data-template-name="<?php echo htmlspecialchars($template['template_name']); ?>">
                                        <i class="fas fa-play"></i> Use
                                    </button>
                                    <a href="?delete_template=<?php echo $template['id']; ?>" 
                                       class="action-btn delete-btn"
                                       onclick="return confirm('Are you sure you want to delete this template?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Recent Quotations Section -->
                <div class="card mt-5">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Quotations</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_quotations)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-file-invoice fa-2x mb-3"></i>
                                <p>No recent quotations found</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Quotation #</th>
                                        <th>Project</th>
                                        <th>Customer</th>
                                        <th>Template Used</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_quotations as $quotation): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($quotation['quotation_number']); ?></td>
                                        <td><?php echo htmlspecialchars($quotation['project_name']); ?></td>
                                        <td><?php echo htmlspecialchars($quotation['customer_name'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($quotation['template_name'] ?: 'Custom'); ?></td>
                                        <td>KES <?php echo number_format($quotation['grand_total'], 2); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($quotation['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline" onclick="viewQuotation(<?php echo $quotation['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- New Template Modal -->
    <div class="modal fade" id="newTemplateModal" tabindex="-1" aria-labelledby="newTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newTemplateModalLabel">Create New Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="templateForm">
                    <div class="modal-body">
                        <ul class="nav nav-tabs" id="templateTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="design-tab" data-bs-toggle="tab" data-bs-target="#design" type="button" role="tab">Design</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="items-tab" data-bs-toggle="tab" data-bs-target="#items" type="button" role="tab">Items</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="templateTabsContent">
                            <!-- Basic Info Tab -->
                            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="form-label">Template Name *</label>
                                            <input type="text" class="form-control" name="template_name" required 
                                                   placeholder="e.g., Standard Drip 1 Acre">
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Template Type *</label>
                                            <select class="form-control form-select" name="template_type" required>
                                                <option value="standard">Standard</option>
                                                <option value="premium">Premium</option>
                                                <option value="custom">Custom</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" rows="3" 
                                                      placeholder="Describe this template..."></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="form-label">Project Name</label>
                                            <input type="text" class="form-control" name="project_name" 
                                                   placeholder="e.g., Farm Irrigation Project">
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Customer Name</label>
                                            <input type="text" class="form-control" name="customer_name" 
                                                   placeholder="Optional">
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Location</label>
                                            <input type="text" class="form-control" name="location" 
                                                   placeholder="e.g., Nyandarua-Murungaru">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Design Tab -->
                            <div class="tab-pane fade" id="design" role="tabpanel">
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="form-label">Land Size *</label>
                                            <input type="number" class="form-control" name="land_size" 
                                                   step="0.01" min="0.01" required value="1">
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Land Unit *</label>
                                            <select class="form-control form-select" name="land_unit" required>
                                                <option value="acres">Acres</option>
                                                <option value="hectares">Hectares</option>
                                                <option value="sqm">Square Meters</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Crop Type *</label>
                                            <select class="form-control form-select" name="crop_type" required>
                                                <option value="vegetables">Vegetables</option>
                                                <option value="fruits">Fruits</option>
                                                <option value="cereals">Cereals</option>
                                                <option value="flowers">Flowers</option>
                                                <option value="pasture">Pasture</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Crop Variety</label>
                                            <input type="text" class="form-control" name="crop_variety" 
                                                   placeholder="e.g., Tomato, Maize, etc.">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="form-label">Irrigation Type *</label>
                                            <select class="form-control form-select" name="irrigation_type" required>
                                                <option value="drip">Drip Irrigation</option>
                                                <option value="sprinkler">Sprinkler</option>
                                                <option value="overhead">Overhead</option>
                                            </select>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label class="form-label">Row Spacing (m)</label>
                                                    <input type="number" class="form-control" name="row_spacing" 
                                                           step="0.01" value="0.3">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label class="form-label">Plant Spacing (m)</label>
                                                    <input type="number" class="form-control" name="plant_spacing" 
                                                           step="0.01" value="0.2">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">Water Pressure (Bar)</label>
                                            <input type="number" class="form-control" name="water_pressure" 
                                                   step="0.1" value="2.0">
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label class="form-label">System Efficiency (%)</label>
                                            <input type="number" class="form-control" name="system_efficiency" 
                                                   min="50" max="95" value="85">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Items Tab -->
                            <div class="tab-pane fade" id="items" role="tabpanel">
                                <div class="mt-4">
                                    <h6>Template Items</h6>
                                    <p class="text-muted">Add items that will be included in quotations generated from this template</p>
                                    
                                    <div class="table-responsive">
                                        <table class="table items-table" id="itemsTable">
                                            <thead>
                                                <tr>
                                                    <th width="40%">Description</th>
                                                    <th width="15%">Units</th>
                                                    <th width="15%">Quantity</th>
                                                    <th width="15%">Rate (KES)</th>
                                                    <th width="10%">Amount</th>
                                                    <th width="5%"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="itemsTableBody">
                                                <!-- Default items from your example -->
                                                <tr>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_description[]" 
                                                               value="HDPE pipe 50mm pn8" required>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_units[]" 
                                                               value="M/Roll" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm quantity-input" 
                                                               name="item_quantity[]" 
                                                               step="0.01" min="0" value="3" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm rate-input" 
                                                               name="item_rate[]" 
                                                               step="0.01" min="0" value="10500" required>
                                                    </td>
                                                    <td class="amount-cell">31,500.00</td>
                                                    <td>
                                                        <button type="button" class="remove-item-btn">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_description[]" 
                                                               value="HDPE pipe 40mm pn10" required>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_units[]" 
                                                               value="M/Roll" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm quantity-input" 
                                                               name="item_quantity[]" 
                                                               step="0.01" min="0" value="3" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm rate-input" 
                                                               name="item_rate[]" 
                                                               step="0.01" min="0" value="7800" required>
                                                    </td>
                                                    <td class="amount-cell">23,400.00</td>
                                                    <td>
                                                        <button type="button" class="remove-item-btn">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                                    <td><strong id="itemsTotal">54,900.00</strong></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <button type="button" class="btn btn-sm add-item-btn" id="addItemBtn">
                                        <i class="fas fa-plus"></i> Add Item
                                    </button>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="form-label">Labor Cost (%)</label>
                                            <input type="number" class="form-control" name="labor_percentage" 
                                                   min="0" max="100" value="35">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="form-label">Discount (%)</label>
                                            <input type="number" class="form-control" name="discount_percentage" 
                                                   min="0" max="50" value="5">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="form-label">Tax Rate (%)</label>
                                            <input type="number" class="form-control" name="tax_rate" 
                                                   min="0" max="30" value="16">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_template" class="btn btn-primary">Create Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Use Template Modal -->
    <div class="modal fade" id="useTemplateModal" tabindex="-1" aria-labelledby="useTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="useTemplateModalLabel">Generate Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="useTemplateForm">
                    <div class="modal-body">
                        <input type="hidden" name="template_id" id="useTemplateId">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Generating quotation from: <strong id="useTemplateName"></strong>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Quotation Number</label>
                            <input type="text" class="form-control" name="quotation_number" 
                                   value="QTN-<?php echo date('Ymd-His'); ?>" readonly>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Project Name *</label>
                            <input type="text" class="form-control" name="project_name" required
                                   placeholder="Enter project name">
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   placeholder="Optional">
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" 
                                   placeholder="e.g., Nyandarua-Murungaru">
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="form-label">Land Size (acres)</label>
                            <input type="number" class="form-control" name="land_size" 
                                   step="0.01" min="0.01" value="1">
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="save_quotation" id="saveQuotation" checked>
                            <label class="form-check-label" for="saveQuotation">
                                Save quotation to database
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="generate_from_template" class="btn btn-primary">Generate Quotation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
        
        // Template items management
        let itemCounter = 2; // Start with 2 default items
        
        // Function to calculate amount for a row
        function calculateRowAmount(row) {
            const quantityInput = row.querySelector('.quantity-input');
            const rateInput = row.querySelector('.rate-input');
            const amountCell = row.querySelector('.amount-cell');
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            const amount = quantity * rate;
            amountCell.textContent = amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            updateTotal();
        }
        
        // Add item row
        document.getElementById('addItemBtn').addEventListener('click', function() {
            const tbody = document.getElementById('itemsTableBody');
            
            itemCounter++;
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="text" class="form-control form-control-sm" 
                           name="item_description[]" 
                           placeholder="Item description" required>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" 
                           name="item_units[]" 
                           placeholder="e.g., M/Roll, nr" required>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm quantity-input" 
                           name="item_quantity[]" 
                           step="0.01" min="0" value="1" required>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm rate-input" 
                           name="item_rate[]" 
                           step="0.01" min="0" value="0" required>
                </td>
                <td class="amount-cell">0.00</td>
                <td>
                    <button type="button" class="remove-item-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(row);
            
            // Add event listeners
            const quantityInput = row.querySelector('.quantity-input');
            const rateInput = row.querySelector('.rate-input');
            
            quantityInput.addEventListener('input', () => calculateRowAmount(row));
            rateInput.addEventListener('input', () => calculateRowAmount(row));
            
            // Remove row
            row.querySelector('.remove-item-btn').addEventListener('click', function() {
                row.remove();
                itemCounter--;
                updateTotal();
            });
        });
        
        // Update total amount
        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.amount-cell').forEach(cell => {
                const amountText = cell.textContent.replace(/,/g, '');
                const amount = parseFloat(amountText) || 0;
                total += amount;
            });
            document.getElementById('itemsTotal').textContent = 
                total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        
        // Initialize event listeners for existing rows
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listeners to existing rows
            document.querySelectorAll('tr').forEach(row => {
                const quantityInput = row.querySelector('.quantity-input');
                const rateInput = row.querySelector('.rate-input');
                const removeBtn = row.querySelector('.remove-item-btn');
                
                if (quantityInput && rateInput) {
                    quantityInput.addEventListener('input', () => calculateRowAmount(row));
                    rateInput.addEventListener('input', () => calculateRowAmount(row));
                }
                
                if (removeBtn) {
                    removeBtn.addEventListener('click', function() {
                        row.remove();
                        itemCounter--;
                        updateTotal();
                    });
                }
            });
            
            updateTotal(); // Initial calculation
        });
        
        // Use Template Modal
        const useTemplateModal = document.getElementById('useTemplateModal');
        if (useTemplateModal) {
            useTemplateModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const templateId = button.getAttribute('data-template-id');
                const templateName = button.getAttribute('data-template-name');
                
                document.getElementById('useTemplateId').value = templateId;
                document.getElementById('useTemplateName').textContent = templateName;
                
                // Generate new quotation number
                const now = new Date();
                const dateStr = now.toISOString().slice(0,10).replace(/-/g, '');
                const timeStr = now.toTimeString().slice(0,8).replace(/:/g, '');
                document.querySelector('#useTemplateForm input[name="quotation_number"]').value = 
                    `QTN-${dateStr}-${timeStr}`;
            });
        }
        
        // View template function
        function viewTemplate(templateId) {
            window.location.href = `view_template.php?id=${templateId}`;
        }
        
        // View quotation function
        function viewQuotation(quotationId) {
            window.location.href = `view_quotation.php?id=${quotationId}`;
        }
        
        // Form validation
        document.getElementById('templateForm').addEventListener('submit', function(e) {
            // Validate at least one item
            if (itemCounter === 0) {
                e.preventDefault();
                alert('Please add at least one item to the template');
                document.getElementById('items-tab').click();
                return false;
            }
            
            return true;
        });
        
        // Reset modal when closed
        document.getElementById('newTemplateModal').addEventListener('hidden.bs.modal', function() {
            // Reset the form if needed
            document.getElementById('templateForm').reset();
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