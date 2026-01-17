<?php
// Start secure session
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']), // Only if using HTTPS
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

// CSRF Token for forms
$csrf_token = generateCSRFToken();

// Database connection
$database = new Database();
$conn = $database->getConnection();

// Get current user with prepared statement
$user_id = intval($_SESSION['user_id']); // Cast to integer for safety
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

// Get active period - FIXED: Always get a valid period
$period_id = 1; // Default fallback
$active_period = null;
$stmt = $conn->prepare("SELECT * FROM time_periods WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$active_period = $result->fetch_assoc();
$stmt->close();

if (!$active_period) {
    // If no active period, get any period
    $stmt = $conn->prepare("SELECT * FROM time_periods LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $active_period = $result->fetch_assoc();
    $stmt->close();
}

if ($active_period) {
    $period_id = intval($active_period['id']);
}

// Function to save quotation to database - SECURED
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
        
        // Sanitize inputs
        $quotation_number = sanitizeInput($quotation['quotation_number'] ?? 'QTN-' . date('Ymd-His'));
        $project_name = sanitizeInput($quotation['project_name'] ?? '');
        $customer_name = sanitizeInput($quotation['customer_name'] ?? '');
        $location = sanitizeInput($quotation['location'] ?? '');
        $land_size = floatval($quotation['land_size'] ?? 0);
        $land_unit = sanitizeInput($quotation['land_unit'] ?? 'acres');
        $crop_type = sanitizeInput($quotation['crop_type'] ?? '');
        $irrigation_type = sanitizeInput($quotation['irrigation_type'] ?? 'drip');
        
        // Validate numeric values
        $total_material = is_numeric($quotation['total_material'] ?? 0) ? floatval($quotation['total_material']) : 0;
        $labor_cost = is_numeric($quotation['labor_cost'] ?? 0) ? floatval($quotation['labor_cost']) : 0;
        $discount_amount = is_numeric($quotation['discount_amount'] ?? 0) ? floatval($quotation['discount_amount']) : 0;
        $tax_amount = is_numeric($quotation['tax_amount'] ?? 0) ? floatval($quotation['tax_amount']) : 0;
        $grand_total = is_numeric($quotation['grand_total'] ?? 0) ? floatval($quotation['grand_total']) : 0;
        
        $items_json = json_encode($quotation['items'] ?? []);
        $template_id = isset($quotation['template_id']) && is_numeric($quotation['template_id']) ? intval($quotation['template_id']) : null;
        
        // Insert quotation with prepared statement
        $stmt = $conn->prepare("
            INSERT INTO irrigation_quotations 
            (quotation_number, project_name, customer_name, location, land_size, land_unit, 
             crop_type, irrigation_type, total_material, labor_cost, discount_amount, 
             tax_amount, grand_total, items_json, template_id, user_id, period_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param(
            "ssssdssddddddssii",
            $quotation_number,
            $project_name,
            $customer_name,
            $location,
            $land_size,
            $land_unit,
            $crop_type,
            $irrigation_type,
            $total_material,
            $labor_cost,
            $discount_amount,
            $tax_amount,
            $grand_total,
            $items_json,
            $template_id,
            $user_id,
            $period_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $quotation_id = $conn->insert_id;
        $stmt->close();
        
        // Log the action
        securityLog("Quotation created: $quotation_number", "INFO", $user_id);
        
        return $quotation_id;
    } catch (Exception $e) {
        error_log("Error saving quotation: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        securityLog("Failed to save quotation: " . $e->getMessage(), "ERROR", $user_id);
        return false;
    }
}

// Function to save template to database - SECURED
function saveTemplateToDatabase($data, $user_id, $conn) {
    try {
        // Create templates table if it doesn't exist
        $create_table = "
        CREATE TABLE IF NOT EXISTS irrigation_templates (
            id INT(11) PRIMARY KEY AUTO_INCREMENT,
            template_name VARCHAR(255) NOT NULL,
            template_type VARCHAR(50) NOT NULL,
            description TEXT,
            project_name VARCHAR(255),
            customer_name VARCHAR(255),
            location VARCHAR(255),
            land_size DECIMAL(10,2),
            land_unit VARCHAR(20),
            crop_type VARCHAR(100),
            crop_variety VARCHAR(100),
            irrigation_type VARCHAR(50),
            row_spacing DECIMAL(5,2),
            plant_spacing DECIMAL(5,2),
            water_pressure DECIMAL(5,2),
            system_efficiency DECIMAL(5,2),
            labor_percentage DECIMAL(5,2),
            discount_percentage DECIMAL(5,2),
            tax_rate DECIMAL(5,2),
            items_json TEXT,
            design_summary TEXT,
            user_id INT(11),
            is_active BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )";
        
        $conn->query($create_table);
        
        // Sanitize inputs
        $template_name = sanitizeInput($data['template_name'] ?? '');
        $template_type = sanitizeInput($data['template_type'] ?? 'standard');
        $description = sanitizeInput($data['description'] ?? '');
        $project_name = sanitizeInput($data['project_name'] ?? '');
        $customer_name = sanitizeInput($data['customer_name'] ?? '');
        $location = sanitizeInput($data['location'] ?? '');
        $crop_variety = sanitizeInput($data['crop_variety'] ?? '');
        $irrigation_type = sanitizeInput($data['irrigation_type'] ?? 'drip');
        $land_unit = sanitizeInput($data['land_unit'] ?? 'acres');
        $crop_type = sanitizeInput($data['crop_type'] ?? '');
        
        // Validate numeric values
        $land_size = is_numeric($data['land_size'] ?? 0) ? floatval($data['land_size']) : 0;
        $row_spacing = is_numeric($data['row_spacing'] ?? 0.3) ? floatval($data['row_spacing']) : 0.3;
        $plant_spacing = is_numeric($data['plant_spacing'] ?? 0.2) ? floatval($data['plant_spacing']) : 0.2;
        $water_pressure = is_numeric($data['water_pressure'] ?? 2.0) ? floatval($data['water_pressure']) : 2.0;
        $system_efficiency = is_numeric($data['system_efficiency'] ?? 85) ? floatval($data['system_efficiency']) : 85;
        $labor_percentage = is_numeric($data['labor_percentage'] ?? 35) ? floatval($data['labor_percentage']) : 35;
        $discount_percentage = is_numeric($data['discount_percentage'] ?? 5) ? floatval($data['discount_percentage']) : 5;
        $tax_rate = is_numeric($data['tax_rate'] ?? 16) ? floatval($data['tax_rate']) : 16;
        
        // Prepare items JSON with sanitization
        $items = [];
        if (isset($data['item_description']) && is_array($data['item_description'])) {
            $count = count($data['item_description']);
            for ($i = 0; $i < $count; $i++) {
                if (!empty($data['item_description'][$i])) {
                    $items[] = [
                        'description' => sanitizeInput($data['item_description'][$i]),
                        'units' => sanitizeInput($data['item_units'][$i] ?? ''),
                        'quantity' => is_numeric($data['item_quantity'][$i] ?? 0) ? floatval($data['item_quantity'][$i]) : 0,
                        'rate' => is_numeric($data['item_rate'][$i] ?? 0) ? floatval($data['item_rate'][$i]) : 0,
                        'amount' => (is_numeric($data['item_quantity'][$i] ?? 0) ? floatval($data['item_quantity'][$i]) : 0) * 
                                   (is_numeric($data['item_rate'][$i] ?? 0) ? floatval($data['item_rate'][$i]) : 0)
                    ];
                }
            }
        }
        $items_json = json_encode($items);
        
        // Prepare design summary
        $design_summary = json_encode([
            'project_name' => $project_name,
            'land_size' => $land_size,
            'land_unit' => $land_unit,
            'crop_type' => $crop_type,
            'irrigation_type' => $irrigation_type
        ]);
        
        // Insert template with prepared statement
        $stmt = $conn->prepare("
            INSERT INTO irrigation_templates
            (template_name, template_type, description, project_name, customer_name, location, 
             land_size, land_unit, crop_type, crop_variety, irrigation_type, row_spacing, 
             plant_spacing, water_pressure, system_efficiency, labor_percentage, discount_percentage, 
             tax_rate, items_json, design_summary, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param(
            "ssssssdssssdddddddssi",
            $template_name,
            $template_type,
            $description,
            $project_name,
            $customer_name,
            $location,
            $land_size,
            $land_unit,
            $crop_type,
            $crop_variety,
            $irrigation_type,
            $row_spacing,
            $plant_spacing,
            $water_pressure,
            $system_efficiency,
            $labor_percentage,
            $discount_percentage,
            $tax_rate,
            $items_json,
            $design_summary,
            $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $template_id = $conn->insert_id;
        $stmt->close();
        
        // Log the action
        securityLog("Template created: $template_name", "INFO", $user_id);
        
        return $template_id;
    } catch (Exception $e) {
        error_log("Error saving template: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        securityLog("Failed to save template: " . $e->getMessage(), "ERROR", $user_id);
        return false;
    }
}

// Add CSRF validation to form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check rate limiting for form submissions
    if (!checkRateLimit('form_submissions', 20, 300)) { // 20 submissions per 5 minutes
        $_SESSION['error_message'] = "Too many submissions. Please wait a few minutes.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Validate CSRF token for all POST requests
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = "Security token invalid or expired. Please try again.";
        securityLog("Invalid CSRF token attempt", "WARNING", $user_id);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle template creation with validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_template'])) {
    // Validate input
    $validationRules = [
        'template_name' => 'required|min:3|max:255',
        'template_type' => 'required',
        'land_size' => 'required|numeric',
        'crop_type' => 'required',
        'irrigation_type' => 'required'
    ];
    
    $validationErrors = validateInput($_POST, $validationRules);
    
    if (empty($validationErrors)) {
        // Sanitize template data
        $template_data = array_map('sanitizeInput', $_POST);
        $template_id = saveTemplateToDatabase($template_data, $user_id, $conn);
        
        if ($template_id) {
            $_SESSION['success_message'] = "Template created successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to create template.";
        }
    } else {
        $_SESSION['error_message'] = "Please fix the following errors: " . implode(', ', $validationErrors);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle quotation generation from template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_from_template'])) {
    $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : null;
    
    // Validate template_id
    if (!$template_id || $template_id <= 0) {
        $_SESSION['error_message'] = "Invalid template selected.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Validate required fields
    $validationRules = [
        'project_name' => 'required|min:3|max:255',
        'land_size' => 'required|numeric'
    ];
    
    $validationErrors = validateInput($_POST, $validationRules);
    
    if (empty($validationErrors)) {
        $quotation_data = array_map('sanitizeInput', $_POST);
        $quotation = generateQuotationFromTemplate($template_id, $quotation_data, $conn);
        
        if ($quotation) {
            if (isset($_POST['save_quotation'])) {
                $period_id_to_use = $period_id;
                $quotation['template_id'] = $template_id;
                $saved = saveQuotationToDatabase($quotation, $user_id, $period_id_to_use, $conn);
                
                if ($saved) {
                    $_SESSION['success_message'] = "Quotation generated and saved successfully!";
                } else {
                    $_SESSION['error_message'] = "Failed to save quotation. Please try again.";
                }
            } else {
                $_SESSION['quotation_data'] = $quotation;
                $_SESSION['success_message'] = "Quotation generated successfully!";
            }
        } else {
            $_SESSION['error_message'] = "Failed to generate quotation. Template not found.";
        }
    } else {
        $_SESSION['error_message'] = "Please fix the following errors: " . implode(', ', $validationErrors);
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle template deletion with authorization check
if (isset($_GET['delete_template'])) {
    $template_id = intval($_GET['delete_template']);
    
    // Verify template belongs to user
    $stmt = $conn->prepare("SELECT user_id FROM irrigation_templates WHERE id = ?");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = $result->fetch_assoc();
    $stmt->close();
    
    if ($template && $template['user_id'] == $user_id) {
        deleteTemplate($template_id, $conn);
        $_SESSION['success_message'] = "Template deleted successfully!";
        securityLog("Template deleted: $template_id", "INFO", $user_id);
    } else {
        $_SESSION['error_message'] = "Unauthorized deletion attempt.";
        securityLog("Unauthorized template deletion attempt: $template_id", "WARNING", $user_id);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Function to get all templates WITH FILTERS - SECURED
function getAllTemplates($user_id, $conn, $filters = []) {
    $sql = "SELECT * FROM irrigation_templates WHERE user_id = ? AND is_active = 1";
    $params = [$user_id];
    $types = "i";
    
    // Apply filters with sanitization
    if (!empty($filters['search'])) {
        $sql .= " AND (template_name LIKE ? OR description LIKE ? OR crop_type LIKE ?)";
        $search_term = "%" . sanitizeInput($filters['search']) . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "sss";
    }
    
    if (!empty($filters['irrigation_type'])) {
        $sql .= " AND irrigation_type = ?";
        $params[] = sanitizeInput($filters['irrigation_type']);
        $types .= "s";
    }
    
    if (!empty($filters['crop_type'])) {
        $sql .= " AND crop_type = ?";
        $params[] = sanitizeInput($filters['crop_type']);
        $types .= "s";
    }
    
    if (!empty($filters['template_type'])) {
        $sql .= " AND template_type = ?";
        $params[] = sanitizeInput($filters['template_type']);
        $types .= "s";
    }
    
    if (!empty($filters['min_land_size'])) {
        $sql .= " AND land_size >= ?";
        $params[] = floatval($filters['min_land_size']);
        $types .= "d";
    }
    
    if (!empty($filters['max_land_size'])) {
        $sql .= " AND land_size <= ?";
        $params[] = floatval($filters['max_land_size']);
        $types .= "d";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $templates = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $templates;
}

// Get filter values with sanitization
$filters = [
    'search' => isset($_GET['search']) ? sanitizeInput($_GET['search']) : '',
    'irrigation_type' => isset($_GET['irrigation_type']) ? sanitizeInput($_GET['irrigation_type']) : '',
    'crop_type' => isset($_GET['crop_type']) ? sanitizeInput($_GET['crop_type']) : '',
    'template_type' => isset($_GET['template_type']) ? sanitizeInput($_GET['template_type']) : '',
    'min_land_size' => isset($_GET['min_land_size']) ? floatval($_GET['min_land_size']) : '',
    'max_land_size' => isset($_GET['max_land_size']) ? floatval($_GET['max_land_size']) : ''
];

// Get all templates for the user WITH FILTERS
$templates = getAllTemplates($user_id, $conn, $filters);

// Get unique values for filter dropdowns
function getUniqueTemplateValues($user_id, $conn, $column) {
    $stmt = $conn->prepare("SELECT DISTINCT $column FROM irrigation_templates WHERE user_id = ? AND is_active = 1 AND $column IS NOT NULL AND $column != '' ORDER BY $column");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $values = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_column($values, $column);
}

$unique_irrigation_types = getUniqueTemplateValues($user_id, $conn, 'irrigation_type');
$unique_crop_types = getUniqueTemplateValues($user_id, $conn, 'crop_type');
$unique_template_types = getUniqueTemplateValues($user_id, $conn, 'template_type');

// Function to get template by ID - SECURED
function getTemplateById($template_id, $conn) {
    if (!$template_id || !is_numeric($template_id)) return null;
    
    $template_id = intval($template_id);
    $stmt = $conn->prepare("SELECT * FROM irrigation_templates WHERE id = ?");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = $result->fetch_assoc();
    $stmt->close();
    
    if ($template) {
        $template['items'] = json_decode($template['items_json'] ?? '[]', true) ?? [];
        $template['design_summary'] = json_decode($template['design_summary'] ?? '[]', true) ?? [];
    }
    
    return $template;
}

// Function to generate quotation from template - SECURED
function generateQuotationFromTemplate($template_id, $data, $conn) {
    $template = getTemplateById($template_id, $conn);
    
    if (!$template) {
        return null;
    }
    
    // Use template data, override with any custom data from form
    $quotation = [
        'quotation_number' => sanitizeInput($data['quotation_number'] ?? 'QTN-' . date('Ymd-His')),
        'project_name' => sanitizeInput($data['project_name'] ?? $template['project_name']),
        'customer_name' => sanitizeInput($data['customer_name'] ?? $template['customer_name']),
        'location' => sanitizeInput($data['location'] ?? $template['location']),
        'land_size' => is_numeric($data['land_size'] ?? $template['land_size']) ? floatval($data['land_size']) : floatval($template['land_size']),
        'land_unit' => sanitizeInput($data['land_unit'] ?? $template['land_unit']),
        'crop_type' => sanitizeInput($data['crop_type'] ?? $template['crop_type']),
        'crop_variety' => sanitizeInput($data['crop_variety'] ?? $template['crop_variety']),
        'irrigation_type' => sanitizeInput($data['irrigation_type'] ?? $template['irrigation_type']),
        'row_spacing' => is_numeric($data['row_spacing'] ?? $template['row_spacing']) ? floatval($data['row_spacing']) : floatval($template['row_spacing']),
        'plant_spacing' => is_numeric($data['plant_spacing'] ?? $template['plant_spacing']) ? floatval($data['plant_spacing']) : floatval($template['plant_spacing']),
        'water_pressure' => is_numeric($data['water_pressure'] ?? $template['water_pressure']) ? floatval($data['water_pressure']) : floatval($template['water_pressure']),
        'system_efficiency' => is_numeric($data['system_efficiency'] ?? $template['system_efficiency']) ? floatval($data['system_efficiency']) : floatval($template['system_efficiency']),
        'labor_percentage' => is_numeric($data['labor_percentage'] ?? $template['labor_percentage']) ? floatval($data['labor_percentage']) : floatval($template['labor_percentage']),
        'discount_percentage' => is_numeric($data['discount_percentage'] ?? $template['discount_percentage']) ? floatval($data['discount_percentage']) : floatval($template['discount_percentage']),
        'tax_rate' => is_numeric($data['tax_rate'] ?? $template['tax_rate']) ? floatval($data['tax_rate']) : floatval($template['tax_rate']),
        'items' => [],
        'template_name' => $template['template_name']
    ];
    
    // Scale items based on land size if needed
    $scale_factor = 1;
    if (isset($data['land_size']) && $template['land_size'] > 0) {
        $scale_factor = floatval($data['land_size']) / $template['land_size'];
    }
    
    // Process template items with sanitization
    if (isset($template['items']) && is_array($template['items'])) {
        foreach ($template['items'] as $item) {
            $scaled_item = [
                'description' => sanitizeInput($item['description'] ?? ''),
                'units' => sanitizeInput($item['units'] ?? ''),
                'quantity' => is_numeric($item['quantity'] ?? 0) ? floatval($item['quantity']) : 0,
                'rate' => is_numeric($item['rate'] ?? 0) ? floatval($item['rate']) : 0
            ];
            
            if ($scale_factor != 1) {
                $scaled_item['quantity'] = ceil($scaled_item['quantity'] * $scale_factor);
            }
            $scaled_item['amount'] = $scaled_item['quantity'] * $scaled_item['rate'];
            
            $quotation['items'][] = $scaled_item;
        }
    }
    
    // Calculate totals
    $total_material = 0;
    if (!empty($quotation['items'])) {
        $total_material = array_sum(array_column($quotation['items'], 'amount'));
    }
    
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

// Function to delete template - SECURED
function deleteTemplate($template_id, $conn) {
    $template_id = intval($template_id);
    $stmt = $conn->prepare("UPDATE irrigation_templates SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $stmt->close();
}

// Get recent quotations - SECURED
function getRecentQuotations($user_id, $conn) {
    $user_id = intval($user_id);
    $stmt = $conn->prepare("
        SELECT q.*, t.template_name 
        FROM irrigation_quotations q
        LEFT JOIN irrigation_templates t ON q.template_id = t.id
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

// Get total templates count
$total_templates = count($templates);

// Get total quotations count
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM irrigation_quotations WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total_quotations = $result->fetch_assoc()['total'] ?? 0;
$stmt->close();

include 'nav_bar.php';
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Irrigation Quotation Templates | Vinmel Irrigation</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">

</head>
<body>
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

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-value"><?php echo htmlspecialchars($total_templates); ?></div>
                                <div class="stats-label">Total Templates</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-value"><?php echo htmlspecialchars($total_quotations); ?></div>
                                <div class="stats-label">Total Quotations</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="fas fa-tint"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-value"><?php echo htmlspecialchars(count(array_filter($templates, function($t) { return ($t['irrigation_type'] ?? '') === 'drip'; }))); ?></div>
                                <div class="stats-label">Drip Templates</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-section">
                    <div class="filter-header">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Filter Templates</h5>
                        <button class="filter-toggle" id="filterToggle">
                            <i class="fas fa-chevron-down"></i>
                            <span>Show Filters</span>
                        </button>
                    </div>
                    
                    <div class="filter-body" id="filterBody">
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-row">
                                <div>
                                    <label class="form-label">Search Templates</label>
                                    <input type="text" class="form-control" name="search" placeholder="Search by name, description, crop..." 
                                           value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                
                                <div>
                                    <label class="form-label">Irrigation Type</label>
                                    <select class="form-control form-select" name="irrigation_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($unique_irrigation_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>" 
                                            <?php echo $filters['irrigation_type'] === $type ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(htmlspecialchars($type, ENT_QUOTES, 'UTF-8')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="form-label">Crop Type</label>
                                    <select class="form-control form-select" name="crop_type">
                                        <option value="">All Crops</option>
                                        <?php foreach ($unique_crop_types as $crop): ?>
                                        <option value="<?php echo htmlspecialchars($crop, ENT_QUOTES, 'UTF-8'); ?>" 
                                            <?php echo $filters['crop_type'] === $crop ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(htmlspecialchars($crop, ENT_QUOTES, 'UTF-8')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="form-label">Template Type</label>
                                    <select class="form-control form-select" name="template_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($unique_template_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>" 
                                            <?php echo $filters['template_type'] === $type ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(htmlspecialchars($type, ENT_QUOTES, 'UTF-8')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-row">
                                <div>
                                    <label class="form-label">Min Land Size</label>
                                    <input type="number" class="form-control" name="min_land_size" 
                                           step="0.01" min="0" placeholder="e.g., 1.0"
                                           value="<?php echo htmlspecialchars($filters['min_land_size'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                
                                <div>
                                    <label class="form-label">Max Land Size</label>
                                    <input type="number" class="form-control" name="max_land_size" 
                                           step="0.01" min="0" placeholder="e.g., 10.0"
                                           value="<?php echo htmlspecialchars($filters['max_land_size'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                
                                <div></div>
                                <div></div>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i> Apply Filters
                                </button>
                                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i> Clear All
                                </a>
                            </div>
                        </form>
                        
                        <!-- Active Filters Display -->
                        <?php if (array_filter($filters)): ?>
                        <div class="active-filters">
                            <strong class="me-2">Active Filters:</strong>
                            <?php foreach ($filters as $key => $value): ?>
                                <?php if (!empty($value)): ?>
                                <div class="filter-badge">
                                    <?php 
                                    $filter_labels = [
                                        'search' => 'Search',
                                        'irrigation_type' => 'Irrigation',
                                        'crop_type' => 'Crop',
                                        'template_type' => 'Type',
                                        'min_land_size' => 'Min Size',
                                        'max_land_size' => 'Max Size'
                                    ];
                                    $display_key = $filter_labels[$key] ?? $key;
                                    ?>
                                    <span><?php echo htmlspecialchars($display_key, ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <a href="?<?php echo htmlspecialchars(http_build_query(array_diff_key($filters, [$key => ''])), ENT_QUOTES, 'UTF-8'); ?>" 
                                       class="remove-filter" title="Remove filter">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Results Summary -->
                <div class="results-summary">
                    <div class="results-count">
                        <?php if (count($templates) === 0): ?>
                            No templates found
                        <?php elseif (count($templates) === 1): ?>
                            1 template found
                        <?php else: ?>
                            <?php echo htmlspecialchars(count($templates), ENT_QUOTES, 'UTF-8'); ?> templates found
                        <?php endif; ?>
                        
                        <?php if (array_filter($filters)): ?>
                            <span class="text-muted ms-2">(filtered)</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (array_filter($filters)): ?>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="clear-filters">
                        <i class="fas fa-times"></i> Clear all filters
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Templates Grid -->
                <div class="row mb-4">
                    <?php if (empty($templates)): ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="fas fa-file-alt fa-3x mb-3"></i>
                            <h4>No Templates Found</h4>
                            <p>
                                <?php if (array_filter($filters)): ?>
                                    No templates match your search criteria. Try adjusting your filters.
                                <?php else: ?>
                                    Create your first irrigation template to get started
                                <?php endif; ?>
                            </p>
                            <?php if (array_filter($filters)): ?>
                            <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary mt-2">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                            <?php else: ?>
                            <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#newTemplateModal">
                                <i class="fas fa-plus"></i> Create First Template
                            </button>
                            <?php endif; ?>
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
                                <span class="template-type-badge template-type-<?php echo htmlspecialchars($irrigation_type, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo strtoupper(htmlspecialchars($irrigation_type, ENT_QUOTES, 'UTF-8')); ?>
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
                                
                                <h5 class="mb-2"><?php echo htmlspecialchars($template['template_name'], ENT_QUOTES, 'UTF-8'); ?></h5>
                                <p class="text-muted small mb-3"><?php echo htmlspecialchars($template['description'] ?? 'No description', ENT_QUOTES, 'UTF-8'); ?></p>
                                
                                <div class="template-details mt-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Area:</span>
                                        <span><?php echo htmlspecialchars(number_format($template['land_size'] ?? 0, 2), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($template['land_unit'] ?? 'acres', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Crop:</span>
                                        <span><?php echo ucfirst(htmlspecialchars($template['crop_type'] ?? 'vegetables', ENT_QUOTES, 'UTF-8')); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Type:</span>
                                        <span><?php echo ucfirst(htmlspecialchars($template['template_type'] ?? 'standard', ENT_QUOTES, 'UTF-8')); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Items:</span>
                                        <span><?php echo htmlspecialchars(count($items), ENT_QUOTES, 'UTF-8'); ?> items</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-3">
                                        <span class="text-muted">Est. Total:</span>
                                        <span class="fw-bold text-primary">KES <?php echo htmlspecialchars(number_format($total), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                                
                                <div class="template-actions d-flex justify-content-between">
                                    <button class="action-btn" onclick="viewTemplate(<?php echo intval($template['id']); ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="action-btn use-template-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#useTemplateModal"
                                            data-template-id="<?php echo intval($template['id']); ?>"
                                            data-template-name="<?php echo htmlspecialchars($template['template_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-play"></i> Use
                                    </button>
                                    <a href="?delete_template=<?php echo intval($template['id']); ?>" 
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
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Recent Quotations</h5>
                        <a href="quotations_list.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_quotations)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-file-invoice fa-2x mb-3"></i>
                                <p>No recent quotations found</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
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
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($quotation['quotation_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars($quotation['project_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($quotation['customer_name'] ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($quotation['template_name'] ?: 'Custom', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-primary fw-bold">KES <?php echo htmlspecialchars(number_format($quotation['grand_total'] ?? 0, 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars(date('M d, Y', strtotime($quotation['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8'); ?></small></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewQuotation(<?php echo intval($quotation['id'] ?? 0); ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="view_quotation.php?id=<?php echo intval($quotation['id'] ?? 0); ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-download"></i>
                                            </a>
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
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newTemplateModalLabel">Create New Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="templateForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
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
                        
                        <div class="tab-content pt-4" id="templateTabsContent">
                            <!-- Basic Info Tab -->
                            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Template Name *</label>
                                            <input type="text" class="form-control" name="template_name" required 
                                                   placeholder="e.g., Standard Drip 1 Acre" maxlength="255">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Template Type *</label>
                                            <select class="form-control form-select" name="template_type" required>
                                                <option value="standard">Standard</option>
                                                <option value="premium">Premium</option>
                                                <option value="custom">Custom</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea class="form-control" name="description" rows="3" 
                                                      placeholder="Describe this template..." maxlength="1000"></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Project Name</label>
                                            <input type="text" class="form-control" name="project_name" 
                                                   placeholder="e.g., Farm Irrigation Project" maxlength="255">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Customer Name</label>
                                            <input type="text" class="form-control" name="customer_name" 
                                                   placeholder="Optional" maxlength="255">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Location</label>
                                            <input type="text" class="form-control" name="location" 
                                                   placeholder="e.g., Nyandarua-Murungaru" maxlength="255">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Design Tab -->
                            <div class="tab-pane fade" id="design" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Land Size *</label>
                                            <input type="number" class="form-control" name="land_size" 
                                                   step="0.01" min="0.01" required value="1" max="10000">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Land Unit *</label>
                                            <select class="form-control form-select" name="land_unit" required>
                                                <option value="acres">Acres</option>
                                                <option value="hectares">Hectares</option>
                                                <option value="sqm">Square Meters</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Crop Type *</label>
                                            <select class="form-control form-select" name="crop_type" required>
                                                <option value="vegetables">Vegetables</option>
                                                <option value="fruits">Fruits</option>
                                                <option value="cereals">Cereals</option>
                                                <option value="flowers">Flowers</option>
                                                <option value="pasture">Pasture</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Crop Variety</label>
                                            <input type="text" class="form-control" name="crop_variety" 
                                                   placeholder="e.g., Tomato, Maize, etc." maxlength="100">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Irrigation Type *</label>
                                            <select class="form-control form-select" name="irrigation_type" required>
                                                <option value="drip">Drip Irrigation</option>
                                                <option value="sprinkler">Sprinkler</option>
                                                <option value="overhead">Overhead</option>
                                            </select>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Row Spacing (m)</label>
                                                    <input type="number" class="form-control" name="row_spacing" 
                                                           step="0.01" value="0.3" min="0.1" max="10">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Plant Spacing (m)</label>
                                                    <input type="number" class="form-control" name="plant_spacing" 
                                                           step="0.01" value="0.2" min="0.1" max="10">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Water Pressure (Bar)</label>
                                            <input type="number" class="form-control" name="water_pressure" 
                                                   step="0.1" value="2.0" min="0.5" max="10">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">System Efficiency (%)</label>
                                            <input type="number" class="form-control" name="system_efficiency" 
                                                   min="50" max="95" value="85">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Items Tab -->
                            <div class="tab-pane fade" id="items" role="tabpanel">
                                <div class="mb-4">
                                    <h6>Template Items</h6>
                                    <p class="text-muted">Add items that will be included in quotations generated from this template</p>
                                    
                                    <div class="table-responsive">
                                        <table class="table" id="itemsTable">
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
                                                <!-- Default items -->
                                                <tr>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_description[]" 
                                                               value="HDPE pipe 50mm pn8" required maxlength="255">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_units[]" 
                                                               value="M/Roll" required maxlength="50">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm quantity-input" 
                                                               name="item_quantity[]" 
                                                               step="0.01" min="0" value="3" required max="1000000">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm rate-input" 
                                                               name="item_rate[]" 
                                                               step="0.01" min="0" value="10500" required max="10000000">
                                                    </td>
                                                    <td class="amount-cell fw-bold">31,500.00</td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_description[]" 
                                                               value="HDPE pipe 40mm pn10" required maxlength="255">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="item_units[]" 
                                                               value="M/Roll" required maxlength="50">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm quantity-input" 
                                                               name="item_quantity[]" 
                                                               step="0.01" min="0" value="3" required max="1000000">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm rate-input" 
                                                               name="item_rate[]" 
                                                               step="0.01" min="0" value="7800" required max="10000000">
                                                    </td>
                                                    <td class="amount-cell fw-bold">23,400.00</td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="4" class="text-end fw-bold">Total:</td>
                                                    <td class="fw-bold text-primary" id="itemsTotal">54,900.00</td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                                        <i class="fas fa-plus"></i> Add Item
                                    </button>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Labor Cost (%)</label>
                                            <input type="number" class="form-control" name="labor_percentage" 
                                                   min="0" max="100" value="35">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Discount (%)</label>
                                            <input type="number" class="form-control" name="discount_percentage" 
                                                   min="0" max="50" value="5">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
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
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_template" class="btn btn-primary">Create Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Use Template Modal - IMPROVED -->
    <div class="modal fade" id="useTemplateModal" tabindex="-1" aria-labelledby="useTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="useTemplateModalLabel">Generate Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="useTemplateForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="modal-body">
                        <input type="hidden" name="template_id" id="useTemplateId">
                        <input type="hidden" name="generate_from_template" value="1">
                        <input type="hidden" name="save_quotation" value="1">
                        
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Generating quotation from: <strong id="useTemplateName"></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Quotation Number</label>
                            <input type="text" class="form-control" name="quotation_number" 
                                   value="QTN-<?php echo date('Ymd-His'); ?>" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Project Name *</label>
                            <input type="text" class="form-control" name="project_name" required
                                   placeholder="Enter project name" maxlength="255">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   placeholder="Optional" maxlength="255">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" 
                                   placeholder="e.g., Nyandarua-Murungaru" maxlength="255">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Land Size (acres)</label>
                            <input type="number" class="form-control" name="land_size" 
                                   step="0.01" min="0.01" value="1" max="10000">
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Clicking "Generate Quotation" will automatically save it to the database.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-invoice-dollar me-1"></i> Generate & Save Quotation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    
    <script>
        // Initialize Bootstrap components
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar) {
                        sidebar.classList.toggle('mobile-open');
                    }
                });
            }
            
            // Initialize Bootstrap tabs
            var triggerTabList = [].slice.call(document.querySelectorAll('#templateTabs button'));
            triggerTabList.forEach(function (triggerEl) {
                var tabTrigger = new bootstrap.Tab(triggerEl);
                triggerEl.addEventListener('click', function (event) {
                    event.preventDefault();
                    tabTrigger.show();
                });
            });
        });
        
        // Filter Toggle Functionality
        const filterToggle = document.getElementById('filterToggle');
        const filterBody = document.getElementById('filterBody');
        let filtersVisible = false;
        
        if (filterToggle && filterBody) {
            // Check if filters are active
            const urlParams = new URLSearchParams(window.location.search);
            filtersVisible = urlParams.toString() !== '';
            
            // Set initial state
            if (filtersVisible) {
                filterBody.classList.remove('collapsed');
                filterToggle.innerHTML = '<i class="fas fa-chevron-up"></i><span>Hide Filters</span>';
            } else {
                filterBody.classList.add('collapsed');
                filterToggle.innerHTML = '<i class="fas fa-chevron-down"></i><span>Show Filters</span>';
            }
            
            filterToggle.addEventListener('click', function() {
                filtersVisible = !filtersVisible;
                if (filtersVisible) {
                    filterBody.classList.remove('collapsed');
                    filterToggle.innerHTML = '<i class="fas fa-chevron-up"></i><span>Hide Filters</span>';
                } else {
                    filterBody.classList.add('collapsed');
                    filterToggle.innerHTML = '<i class="fas fa-chevron-down"></i><span>Show Filters</span>';
                }
            });
        }
        
        // Quick filter chips
        function setupFilterChips() {
            const filterChips = document.querySelectorAll('.remove-filter');
            filterChips.forEach(chip => {
                chip.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Navigate to the URL without this specific filter
                    window.location.href = this.getAttribute('href');
                });
            });
        }
        
        setupFilterChips();
        
        // Template items management
        let itemCounter = 2;
        
        function calculateRowAmount(row) {
            const quantityInput = row.querySelector('.quantity-input');
            const rateInput = row.querySelector('.rate-input');
            const amountCell = row.querySelector('.amount-cell');
            
            if (!quantityInput || !rateInput || !amountCell) return;
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            const amount = quantity * rate;
            amountCell.textContent = amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            updateTotal();
        }
        
        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.amount-cell').forEach(cell => {
                const amountText = cell.textContent.replace(/,/g, '');
                const amount = parseFloat(amountText) || 0;
                total += amount;
            });
            const totalElement = document.getElementById('itemsTotal');
            if (totalElement) {
                totalElement.textContent = 
                    total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        }
        
        document.getElementById('addItemBtn').addEventListener('click', function() {
            const tbody = document.getElementById('itemsTableBody');
            if (!tbody) return;
            
            itemCounter++;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="text" class="form-control form-control-sm" 
                           name="item_description[]" 
                           placeholder="Item description" required maxlength="255">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" 
                           name="item_units[]" 
                           placeholder="e.g., M/Roll" required maxlength="50">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm quantity-input" 
                           name="item_quantity[]" 
                           step="0.01" min="0" value="1" required max="1000000">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm rate-input" 
                           name="item_rate[]" 
                           step="0.01" min="0" value="0" required max="10000000">
                </td>
                <td class="amount-cell fw-bold">0.00</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(row);
            
            const quantityInput = row.querySelector('.quantity-input');
            const rateInput = row.querySelector('.rate-input');
            const removeBtn = row.querySelector('.remove-item-btn');
            
            if (quantityInput && rateInput) {
                quantityInput.addEventListener('input', () => calculateRowAmount(row));
                rateInput.addEventListener('input', () => calculateRowAmount(row));
                
                // Trigger initial calculation
                calculateRowAmount(row);
            }
            
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    row.remove();
                    itemCounter--;
                    updateTotal();
                });
            }
        });
        
        // Initialize existing rows
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#itemsTableBody tr').forEach(row => {
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
            
            updateTotal();
        });
        
        // Use Template Modal functionality - IMPROVED
        const useTemplateModal = document.getElementById('useTemplateModal');
        if (useTemplateModal) {
            useTemplateModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const templateId = button.getAttribute('data-template-id');
                const templateName = button.getAttribute('data-template-name');
                
                const templateIdInput = document.getElementById('useTemplateId');
                const templateNameSpan = document.getElementById('useTemplateName');
                
                if (templateIdInput) templateIdInput.value = templateId;
                if (templateNameSpan) templateNameSpan.textContent = templateName;
                
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
        
        // View template function
        function viewTemplate(templateId) {
            window.location.href = `view_template.php?id=${templateId}`;
        }
        
        // View quotation function
        function viewQuotation(quotationId) {
            window.location.href = `view_quotation.php?id=${quotationId}`;
        }
        
        // Quick filter by type
        function quickFilter(type, value) {
            const form = document.getElementById('filterForm');
            const input = form.querySelector(`[name="${type}"]`);
            if (input) {
                input.value = value;
                form.submit();
            }
        }
        
        // Form validation
        document.getElementById('templateForm')?.addEventListener('submit', function(e) {
            const templateName = this.querySelector('input[name="template_name"]');
            if (templateName && !templateName.value.trim()) {
                e.preventDefault();
                alert('Template name is required');
                templateName.focus();
            }
        });
        
        // Auto-focus on modal show
        const newTemplateModal = document.getElementById('newTemplateModal');
        if (newTemplateModal) {
            newTemplateModal.addEventListener('shown.bs.modal', function() {
                const input = this.querySelector('input[name="template_name"]');
                if (input) input.focus();
            });
        }
        
        // Show loading state on form submission
        document.getElementById('useTemplateForm')?.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';
                submitBtn.disabled = true;
            }
        });
        
        // Auto-submit filter form when certain fields change
        document.querySelectorAll('#filterForm select').forEach(select => {
            select.addEventListener('change', function() {
                // Don't auto-submit if the select is empty (meaning "All" was selected)
                if (this.value !== '') {
                    document.getElementById('filterForm').submit();
                }
            });
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