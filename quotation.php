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


function saveQuotationToDatabase($quotation, $user_id, $period_id, $conn) {
    try {
        // Log start of process
        error_log("Starting quotation save for user: $user_id");
        
        // Sanitize inputs
        $quotation_number = sanitizeInput($quotation['quotation_number'] ?? 'QTN-' . date('Ymd-His'));
        $project_name = sanitizeInput($quotation['project_name'] ?? '');
        $customer_name = sanitizeInput($quotation['customer_name'] ?? '');
        $location = sanitizeInput($quotation['location'] ?? '');
        $land_size = floatval($quotation['land_size'] ?? 0);
        $land_unit = sanitizeInput($quotation['land_unit'] ?? 'acres');
        $crop_type = sanitizeInput($quotation['crop_type'] ?? '');
        $irrigation_type = sanitizeInput($quotation['irrigation_type'] ?? 'drip');
        $is_hybrid = isset($quotation['is_hybrid']) && $quotation['is_hybrid'] ? 1 : 0;
        
        // Handle hybrid configuration
        $hybrid_config = null;
        if ($is_hybrid && isset($quotation['hybrid_config'])) {
            $hybrid_config_json = json_encode($quotation['hybrid_config']);
            $hybrid_config = $hybrid_config_json !== false ? $hybrid_config_json : null;
        }
        
        // Validate numeric values
        $total_material = is_numeric($quotation['total_material'] ?? 0) ? floatval($quotation['total_material']) : 0;
        $labor_cost = is_numeric($quotation['labor_cost'] ?? 0) ? floatval($quotation['labor_cost']) : 0;
        $discount_amount = is_numeric($quotation['discount_amount'] ?? 0) ? floatval($quotation['discount_amount']) : 0;
        $tax_amount = is_numeric($quotation['tax_amount'] ?? 0) ? floatval($quotation['tax_amount']) : 0;
        $grand_total = is_numeric($quotation['grand_total'] ?? 0) ? floatval($quotation['grand_total']) : 0;
        
        // Handle items JSON - ensure valid JSON
        $items = $quotation['items'] ?? [];
        $items_json = json_encode($items);
        if ($items_json === false) {
            error_log("JSON encode failed for items: " . print_r($items, true));
            $items_json = '[]'; // Default to empty array
        }
        
        $template_id = isset($quotation['template_id']) && is_numeric($quotation['template_id']) ? intval($quotation['template_id']) : null;
        
        // Debug logging
        error_log("Attempting to save quotation with params:");
        error_log("Quotation #: $quotation_number");
        error_log("Project: $project_name");
        error_log("User ID: $user_id");
        error_log("Period ID: $period_id");
        error_log("Is Hybrid: $is_hybrid");
        error_log("Items JSON length: " . strlen($items_json));
        
        // Insert quotation with prepared statement
        $stmt = $conn->prepare("
            INSERT INTO irrigation_quotations 
            (quotation_number, project_name, customer_name, location, land_size, land_unit, 
             crop_type, irrigation_type, is_hybrid, hybrid_config, total_material, labor_cost, discount_amount, 
             tax_amount, grand_total, items_json, template_id, user_id, period_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            $error_msg = "Prepare failed: " . $conn->error . " (Error #: " . $conn->errno . ")";
            error_log($error_msg);
            throw new Exception($error_msg);
        }
        
        // For debugging, log the parameter types
        error_log("Parameter types: ssssdsssissdddddsii");
        
        // IMPORTANT FIX: Handle NULL for hybrid_config properly
        $stmt->bind_param(
            "ssssdsssissdddddsii",  // Added 's' for hybrid_config (string or NULL)
            $quotation_number,
            $project_name,
            $customer_name,
            $location,
            $land_size,
            $land_unit,
            $crop_type,
            $irrigation_type,
            $is_hybrid,
            $hybrid_config,
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
            $error_msg = "Execute failed: " . $stmt->error . " (Error #: " . $stmt->errno . ")";
            error_log($error_msg);
            
            // Additional debug: Show the actual SQL being executed
            error_log("SQL State: " . ($stmt->sqlstate ?? 'Unknown'));
            throw new Exception($error_msg);
        }
        
        $quotation_id = $conn->insert_id;
        $stmt->close();
        
        error_log("Quotation saved successfully with ID: $quotation_id");
        
        // Log the action
        securityLog("Quotation created: $quotation_number", "INFO", $user_id);
        
        return $quotation_id;
    } catch (Exception $e) {
        error_log("Error saving quotation: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        
        // Also log to error file for debugging
        $debug_log = "Quotation Save Error at " . date('Y-m-d H:i:s') . ":\n";
        $debug_log .= "Message: " . $e->getMessage() . "\n";
        $debug_log .= "Trace: " . $e->getTraceAsString() . "\n";
        $debug_log .= "User ID: " . ($user_id ?? 'Unknown') . "\n";
        $debug_log .= "Quotation Data: " . print_r($quotation, true) . "\n";
        file_put_contents('quotation_errors.log', $debug_log, FILE_APPEND);
        
        securityLog("Failed to save quotation: " . $e->getMessage(), "ERROR", $user_id);
        return false;
    }
}

// Function to save template to database - SECURED (UPDATED FOR HYBRID WITH CROP VARIETIES)
function saveTemplateToDatabase($data, $user_id, $conn) {
    try {
        // Sanitize inputs
        $template_name = sanitizeInput($data['template_name'] ?? '');
        $template_type = sanitizeInput($data['template_type'] ?? 'standard');
        $description = sanitizeInput($data['description'] ?? '');
        $project_name = sanitizeInput($data['project_name'] ?? '');
        $customer_name = sanitizeInput($data['customer_name'] ?? '');
        $location = sanitizeInput($data['location'] ?? '');
        $irrigation_type = sanitizeInput($data['irrigation_type'] ?? 'drip');
        $land_unit = sanitizeInput($data['land_unit'] ?? 'acres');
        $crop_type = sanitizeInput($data['crop_type'] ?? '');
        
        // Check if it's a hybrid system
        $is_hybrid = (isset($data['irrigation_type']) && $data['irrigation_type'] === 'hybrid') ? 1 : 0;
        $hybrid_config = null;
        
        // Process crop varieties
        $crop_varieties = [];
        if (isset($data['variety_crop_type']) && is_array($data['variety_crop_type'])) {
            $count = count($data['variety_crop_type']);
            for ($i = 0; $i < $count; $i++) {
                // Only process if crop variety is provided
                if (!empty($data['variety_name'][$i])) {
                    $variety_data = [
                        'crop_type' => sanitizeInput($data['variety_crop_type'][$i]),
                        'variety_name' => sanitizeInput($data['variety_name'][$i]),
                        'irrigation_method' => isset($data['variety_irrigation_method'][$i]) ? 
                                               sanitizeInput($data['variety_irrigation_method'][$i]) : 
                                               ($is_hybrid ? 'drip' : $irrigation_type),
                        'area_percentage' => isset($data['variety_area_percentage'][$i]) ? 
                                            floatval($data['variety_area_percentage'][$i]) : 100,
                        'parameters' => []
                    ];
                    
                    // Process parameters for this variety
                    if (isset($data['variety_parameters'][$i]) && is_array($data['variety_parameters'][$i])) {
                        foreach ($data['variety_parameters'][$i] as $param) {
                            if (!empty($param['row_spacing']) || !empty($param['plant_spacing']) || !empty($param['water_pressure'])) {
                                $variety_data['parameters'][] = [
                                    'row_spacing' => isset($param['row_spacing']) ? floatval($param['row_spacing']) : 0.3,
                                    'plant_spacing' => isset($param['plant_spacing']) ? floatval($param['plant_spacing']) : 0.2,
                                    'water_pressure' => isset($param['water_pressure']) ? floatval($param['water_pressure']) : 2.0,
                                    'notes' => isset($param['notes']) ? sanitizeInput($param['notes']) : ''
                                ];
                            }
                        }
                    }
                    
                    // Process items for this variety
                    $variety_items = [];
                    if (isset($data['variety_items'][$i]) && is_array($data['variety_items'][$i])) {
                        foreach ($data['variety_items'][$i] as $item) {
                            if (!empty($item['description'])) {
                                $variety_items[] = [
                                    'description' => sanitizeInput($item['description']),
                                    'units' => sanitizeInput($item['units'] ?? ''),
                                    'quantity' => isset($item['quantity']) ? floatval($item['quantity']) : 0,
                                    'rate' => isset($item['rate']) ? floatval($item['rate']) : 0,
                                    'amount' => (isset($item['quantity']) ? floatval($item['quantity']) : 0) * 
                                               (isset($item['rate']) ? floatval($item['rate']) : 0)
                                ];
                            }
                        }
                    }
                    $variety_data['items'] = $variety_items;
                    
                    $crop_varieties[] = $variety_data;
                }
            }
        }
        
        // Prepare hybrid configuration
        if ($is_hybrid) {
            $hybrid_config = [
                'varieties' => $crop_varieties
            ];
        }
        
        // Validate numeric values
        $land_size = is_numeric($data['land_size'] ?? 0) ? floatval($data['land_size']) : 0;
        $system_efficiency = is_numeric($data['system_efficiency'] ?? 85) ? floatval($data['system_efficiency']) : 85;
        $labor_percentage = is_numeric($data['labor_percentage'] ?? 35) ? floatval($data['labor_percentage']) : 35;
        $discount_percentage = is_numeric($data['discount_percentage'] ?? 5) ? floatval($data['discount_percentage']) : 5;
        $tax_rate = is_numeric($data['tax_rate'] ?? 16) ? floatval($data['tax_rate']) : 16;
        
        // Prepare design summary
        $design_summary = json_encode([
            'project_name' => $project_name,
            'land_size' => $land_size,
            'land_unit' => $land_unit,
            'crop_type' => $crop_type,
            'irrigation_type' => $irrigation_type,
            'is_hybrid' => $is_hybrid,
            'variety_count' => count($crop_varieties)
        ]);
        
        $hybrid_config_json = $is_hybrid ? json_encode($hybrid_config) : null;
        $items_json = '[]'; // Items are now stored per variety
        
        // Insert template with prepared statement
        $stmt = $conn->prepare("
            INSERT INTO irrigation_templates
            (template_name, template_type, description, project_name, customer_name, location, 
             land_size, land_unit, crop_type, irrigation_type, is_hybrid, hybrid_config,
             system_efficiency, labor_percentage, discount_percentage, 
             tax_rate, items_json, design_summary, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param(
            "ssssssdsssisddddssi",
            $template_name,
            $template_type,
            $description,
            $project_name,
            $customer_name,
            $location,
            $land_size,
            $land_unit,
            $crop_type,
            $irrigation_type,
            $is_hybrid,
            $hybrid_config_json,
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
        
        // Save crop varieties to separate table
        if ($template_id && !empty($crop_varieties)) {
            foreach ($crop_varieties as $variety) {
                $stmt_variety = $conn->prepare("
                    INSERT INTO template_crop_varieties 
                    (template_id, crop_type, crop_variety, irrigation_method, area_percentage, 
                     parameters_json, items_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $parameters_json = json_encode($variety['parameters'] ?? []);
                $items_json = json_encode($variety['items'] ?? []);
                
                $stmt_variety->bind_param(
                    "isssdss",
                    $template_id,
                    $variety['crop_type'],
                    $variety['variety_name'],
                    $variety['irrigation_method'],
                    $variety['area_percentage'],
                    $parameters_json,
                    $items_json
                );
                
                $stmt_variety->execute();
                $stmt_variety->close();
            }
        }
        
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
    
    // Additional validation for hybrid systems
    if (isset($_POST['irrigation_type']) && $_POST['irrigation_type'] === 'hybrid') {
        // Validate that total area percentage equals 100%
        if (isset($_POST['variety_area_percentage']) && is_array($_POST['variety_area_percentage'])) {
            $total_percentage = array_sum(array_map('floatval', $_POST['variety_area_percentage']));
            if (abs($total_percentage - 100) > 0.01) {
                $validationErrors[] = "Total area percentage for hybrid system must equal 100% (Current: {$total_percentage}%)";
            }
        }
        
        // Validate that at least one variety is added
        if (!isset($_POST['variety_name']) || empty(array_filter($_POST['variety_name']))) {
            $validationErrors[] = "Please add at least one crop variety for hybrid system";
        }
    }
    
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
    
    // Get crop varieties for each template
    foreach ($templates as &$template) {
        $stmt = $conn->prepare("SELECT * FROM template_crop_varieties WHERE template_id = ?");
        $stmt->bind_param("i", $template['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $template['crop_varieties'] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Decode JSON fields
        foreach ($template['crop_varieties'] as &$variety) {
            $variety['parameters'] = json_decode($variety['parameters_json'] ?? '[]', true) ?? [];
            $variety['items'] = json_decode($variety['items_json'] ?? '[]', true) ?? [];
        }
    }
    
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
        $template['design_summary'] = json_decode($template['design_summary'] ?? '[]', true) ?? [];
        $template['hybrid_config'] = json_decode($template['hybrid_config'] ?? '[]', true) ?? [];
        
        // Get crop varieties
        $stmt = $conn->prepare("SELECT * FROM template_crop_varieties WHERE template_id = ?");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $template['crop_varieties'] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Decode JSON fields for each variety
        foreach ($template['crop_varieties'] as &$variety) {
            $variety['parameters'] = json_decode($variety['parameters_json'] ?? '[]', true) ?? [];
            $variety['items'] = json_decode($variety['items_json'] ?? '[]', true) ?? [];
        }
    }
    
    return $template;
}

// Function to generate hybrid quotation
function generateHybridQuotation($template, $data, $conn) {
    $quotation = [
        'quotation_number' => sanitizeInput($data['quotation_number'] ?? 'QTN-' . date('Ymd-His')),
        'project_name' => sanitizeInput($data['project_name'] ?? $template['project_name']),
        'customer_name' => sanitizeInput($data['customer_name'] ?? $template['customer_name']),
        'location' => sanitizeInput($data['location'] ?? $template['location']),
        'land_size' => is_numeric($data['land_size'] ?? $template['land_size']) ? floatval($data['land_size']) : floatval($template['land_size']),
        'land_unit' => sanitizeInput($data['land_unit'] ?? $template['land_unit']),
        'crop_type' => 'Multiple',
        'irrigation_type' => 'hybrid',
        'is_hybrid' => true,
        'hybrid_config' => $template['hybrid_config'],
        'crop_varieties' => [],
        'items' => [],
        'template_name' => $template['template_name']
    ];
    
    // Scale land size for each variety based on percentage
    $total_land = $quotation['land_size'];
    
    foreach ($template['crop_varieties'] as $variety) {
        $variety_land_size = $total_land * ($variety['area_percentage'] / 100);
        
        $quotation_variety = [
            'crop_type' => $variety['crop_type'],
            'crop_variety' => $variety['crop_variety'],
            'irrigation_method' => $variety['irrigation_method'],
            'area_percentage' => $variety['area_percentage'],
            'land_size' => $variety_land_size,
            'parameters' => $variety['parameters'],
            'items' => []
        ];
        
        // Scale items based on land size
        if (!empty($variety['items'])) {
            foreach ($variety['items'] as $item) {
                $scaled_item = $item;
                // Scale quantity if needed
                if ($template['land_size'] > 0) {
                    $scale_factor = $variety_land_size / $template['land_size'];
                    $scaled_item['quantity'] = ceil($item['quantity'] * $scale_factor);
                    $scaled_item['amount'] = $scaled_item['quantity'] * $scaled_item['rate'];
                }
                $quotation_variety['items'][] = $scaled_item;
                
                // Add to main items array with variety prefix
                $main_item = $scaled_item;
                $main_item['description'] = "[{$variety['crop_variety']}] " . $main_item['description'];
                $quotation['items'][] = $main_item;
            }
        }
        
        $quotation['crop_varieties'][] = $quotation_variety;
    }
    
    // Calculate totals
    $total_material = array_sum(array_column($quotation['items'], 'amount'));
    $labor_cost = $total_material * ($template['labor_percentage'] / 100);
    $subtotal = $total_material + $labor_cost;
    $discount_amount = $subtotal * ($template['discount_percentage'] / 100);
    $taxable_amount = $subtotal - $discount_amount;
    $tax_amount = $taxable_amount * ($template['tax_rate'] / 100);
    $grand_total = $taxable_amount + $tax_amount;
    
    $quotation['total_material'] = $total_material;
    $quotation['labor_cost'] = $labor_cost;
    $quotation['discount_amount'] = $discount_amount;
    $quotation['tax_amount'] = $tax_amount;
    $quotation['grand_total'] = $grand_total;
    $quotation['labor_percentage'] = $template['labor_percentage'];
    $quotation['discount_percentage'] = $template['discount_percentage'];
    $quotation['tax_rate'] = $template['tax_rate'];
    
    return $quotation;
}

// Function to generate quotation from template - UPDATED FOR HYBRID
function generateQuotationFromTemplate($template_id, $data, $conn) {
    $template = getTemplateById($template_id, $conn);
    
    if (!$template) {
        return null;
    }
    
    // Check if it's a hybrid template
    $is_hybrid = ($template['is_hybrid'] ?? 0) == 1;
    
    if ($is_hybrid) {
        return generateHybridQuotation($template, $data, $conn);
    } else {
        // Original non-hybrid generation logic
        $quotation = [
            'quotation_number' => sanitizeInput($data['quotation_number'] ?? 'QTN-' . date('Ymd-His')),
            'project_name' => sanitizeInput($data['project_name'] ?? $template['project_name']),
            'customer_name' => sanitizeInput($data['customer_name'] ?? $template['customer_name']),
            'location' => sanitizeInput($data['location'] ?? $template['location']),
            'land_size' => is_numeric($data['land_size'] ?? $template['land_size']) ? floatval($data['land_size']) : floatval($template['land_size']),
            'land_unit' => sanitizeInput($data['land_unit'] ?? $template['land_unit']),
            'crop_type' => sanitizeInput($data['crop_type'] ?? $template['crop_type']),
            'irrigation_type' => sanitizeInput($data['irrigation_type'] ?? $template['irrigation_type']),
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
        
        // Process template items from varieties
        if (!empty($template['crop_varieties'])) {
            foreach ($template['crop_varieties'] as $variety) {
                if (!empty($variety['items'])) {
                    foreach ($variety['items'] as $item) {
                        $scaled_item = [
                            'description' => "[{$variety['crop_variety']}] " . sanitizeInput($item['description'] ?? ''),
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
    <style>
        /* Prevent browser validation on hidden required fields */
        [style*="display: none"] [required],
        [hidden] [required] {
            pointer-events: none;
        }
        
        /* Additional styles for better UX */
        .variety-section {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background-color: #f8f9fa;
        }
        
        .variety-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .variety-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            background-color: #0d6efd;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            font-weight: bold;
        }
        
        .variety-title {
            font-weight: bold;
            color: #495057;
        }
        
        .parameter-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-bottom: 10px;
        }
        
        @media (max-width: 992px) {
            .parameter-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .parameter-row {
                grid-template-columns: 1fr;
            }
        }
        
        .items-table {
            font-size: 0.9rem;
        }
        
        .items-table th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        
        .items-table input {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
        }
        
        /* Filter section styles */
        .filter-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background-color: #e9ecef;
            border-bottom: 1px solid #dee2e6;
        }
        
        .filter-body {
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .filter-body.collapsed {
            display: none;
        }
        
        .filter-toggle {
            background: none;
            border: none;
            color: #0d6efd;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .filter-toggle:hover {
            color: #0a58ca;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 1200px) {
            .filter-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .filter-row {
                grid-template-columns: 1fr;
            }
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        
        .filter-badge {
            background-color: #e7f1ff;
            border: 1px solid #b6d4fe;
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .remove-filter {
            color: #6c757d;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .remove-filter:hover {
            color: #dc3545;
        }
        
        .results-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .results-count {
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        .clear-filters {
            color: #6c757d;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .clear-filters:hover {
            color: #dc3545;
        }
        
        /* Template card styles */
        .template-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            height: 100%;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .template-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .template-type-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .template-type-drip {
            background-color: #d1e7ff;
            color: #0a58ca;
            border: 1px solid #b6d4fe;
        }
        
        .template-type-sprinkler {
            background-color: #fff3cd;
            color: #997404;
            border: 1px solid #ffecb5;
        }
        
        .template-type-rain_hose {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .template-type-overhead {
            background-color: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }
        
        .template-type-hybrid {
            background: linear-gradient(135deg, #6f42c1, #0d6efd);
            color: white;
            border: none;
        }
        
        .template-icon {
            width: 60px;
            height: 60px;
            background-color: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 1.5rem;
            color: #0d6efd;
        }
        
        .hybrid-info-badge {
            margin-bottom: 10px;
        }
        
        .template-details {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .template-actions {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        
        .action-btn {
            background: none;
            border: none;
            color: #0d6efd;
            cursor: pointer;
            padding: 5px 0;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        
        .action-btn:hover {
            color: #0a58ca;
        }
        
        .action-btn.delete-btn {
            color: #dc3545;
        }
        
        .action-btn.delete-btn:hover {
            color: #b02a37;
        }
        
        /* Stats cards */
        .stats-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stats-icon {
            width: 50px;
            height: 50px;
            background-color: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #0d6efd;
        }
        
        .stats-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #212529;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background-color: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }
        
        /* Modal improvements */
        .modal-content {
            border-radius: 12px;
            border: none;
        }
        
        .modal-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        /* Tab improvements */
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            padding: 10px 20px;
        }
        
        .nav-tabs .nav-link:hover {
            color: #0d6efd;
        }
        
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            background: none;
        }
        
        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
    </style>
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
                                <i class="fas fa-random"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-value"><?php echo htmlspecialchars(count(array_filter($templates, function($t) { return ($t['is_hybrid'] ?? 0) == 1; }))); ?></div>
                                <div class="stats-label">Hybrid Templates</div>
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
                            // Calculate total from all variety items
                            $total = 0;
                            $variety_count = 0;
                            
                            if (!empty($template['crop_varieties'])) {
                                $variety_count = count($template['crop_varieties']);
                                foreach ($template['crop_varieties'] as $variety) {
                                    if (!empty($variety['items'])) {
                                        foreach ($variety['items'] as $item) {
                                            $total += ($item['amount'] ?? ($item['quantity'] ?? 0) * ($item['rate'] ?? 0));
                                        }
                                    }
                                }
                            }
                            
                            // Get irrigation type for styling
                            $irrigation_type = $template['irrigation_type'] ?? 'drip';
                            $is_hybrid = ($template['is_hybrid'] ?? 0) == 1;
                        ?>
                        <div class="col-md-4 mb-4">
                            <div class="template-card">
                                <span class="template-type-badge template-type-<?php echo $is_hybrid ? 'hybrid' : htmlspecialchars($irrigation_type, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php 
                                    if ($is_hybrid) {
                                        echo 'HYBRID';
                                    } else {
                                        echo strtoupper(htmlspecialchars($irrigation_type, ENT_QUOTES, 'UTF-8'));
                                    }
                                    ?>
                                </span>
                                
                                <div class="template-icon">
                                    <?php if ($is_hybrid): ?>
                                        <i class="fas fa-random"></i>
                                    <?php elseif ($irrigation_type == 'drip'): ?>
                                        <i class="fas fa-tint"></i>
                                    <?php elseif ($irrigation_type == 'sprinkler'): ?>
                                        <i class="fas fa-shower"></i>
                                    <?php elseif ($irrigation_type == 'rain_hose'): ?>
                                        <i class="fas fa-cloud-rain"></i>
                                    <?php else: ?>
                                        <i class="fas fa-cloud-rain"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($is_hybrid): ?>
                                <div class="hybrid-info-badge">
                                    <small class="text-muted">
                                        <i class="fas fa-layer-group"></i>
                                        <?php echo $variety_count; ?> variet<?php echo $variety_count != 1 ? 'ies' : 'y'; ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                
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
                                        <span class="text-muted">Varieties:</span>
                                        <span><?php echo $variety_count; ?> variet<?php echo $variety_count != 1 ? 'ies' : 'y'; ?></span>
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
                                            data-template-name="<?php echo htmlspecialchars($template['template_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-is-hybrid="<?php echo $is_hybrid ? '1' : '0'; ?>"
                                            data-variety-count="<?php echo $variety_count; ?>">
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
                                    <?php foreach ($recent_quotations as $quotation): 
                                        $is_hybrid_quote = ($quotation['is_hybrid'] ?? 0) == 1;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge <?php echo $is_hybrid_quote ? 'bg-purple' : 'bg-primary'; ?>">
                                                <?php echo htmlspecialchars($quotation['quotation_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($is_hybrid_quote): ?>
                                                    <i class="fas fa-random ms-1" title="Hybrid System"></i>
                                                <?php endif; ?>
                                            </span>
                                        </td>
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
            <div class="modal-content" style="max-height: 90vh; display: flex; flex-direction: column;">
                <div class="modal-header" style="flex-shrink: 0;">
                    <h5 class="modal-title" id="newTemplateModalLabel">Create New Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="overflow-y: auto; flex-grow: 1;">
                    <form method="POST" action="" id="templateForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <ul class="nav nav-tabs" id="templateTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="crops-tab" data-bs-toggle="tab" data-bs-target="#crops" type="button" role="tab">Crop Varieties</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab">Settings</button>
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
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Crop Type *</label>
                                            <select class="form-control form-select" name="crop_type" id="mainCropType" required>
                                                <option value="vegetables">Vegetables</option>
                                                <option value="fruits">Fruits</option>
                                                <option value="cereals">Cereals</option>
                                                <option value="flowers">Flowers</option>
                                                <option value="pasture">Pasture</option>
                                                <option value="oranges">Oranges</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Irrigation Type *</label>
                                            <select class="form-control form-select" name="irrigation_type" id="irrigationType" required onchange="toggleHybridFields()">
                                                <option value="drip">Drip Irrigation</option>
                                                <option value="sprinkler">Sprinkler</option>
                                                <option value="overhead">Overhead</option>
                                                <option value="rain_hose">Rain Hose</option>
                                                <option value="hybrid">Hybrid System</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Crop Varieties Tab -->
                            <div class="tab-pane fade" id="crops" role="tabpanel">
                                <div id="hybridConfigSection" style="display: none; padding-bottom: 20px;">
                                    <div class="alert alert-info mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Hybrid System:</strong> Add multiple crop varieties with different irrigation methods. Each variety can have multiple parameter configurations.
                                    </div>
                                    
                                    <!-- Varieties Container -->
                                    <div id="varietiesContainer" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                                        <!-- Varieties will be added here dynamically -->
                                    </div>
                                    
                                    <div class="text-center mt-3">
                                        <button type="button" class="btn btn-outline-primary" id="addVarietyBtn">
                                            <i class="fas fa-plus me-1"></i> Add Crop Variety
                                        </button>
                                    </div>
                                    
                                    <!-- Area Percentage Validation -->
                                    <div class="alert alert-warning mt-3" id="areaWarning" style="display: none;">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Total area percentage must equal 100%
                                        <span id="currentTotal">(Current: 0%)</span>
                                    </div>
                                </div>
                                
                                <div id="standardCropSection" style="padding-bottom: 20px;">
                                    <div class="alert alert-info mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Standard System:</strong> Add crop varieties for this irrigation system.
                                    </div>
                                    
                                    <!-- Standard Varieties Container -->
                                    <div id="standardVarietiesContainer" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                                        <div class="variety-section" data-variety-index="0">
                                            <div class="variety-header">
                                                <div>
                                                    <span class="variety-number">1</span>
                                                    <span class="variety-title">Crop Variety</span>
                                                </div>
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-outline-success add-parameter-btn" data-variety-index="0">
                                                        <i class="fas fa-plus me-1"></i> Add Parameters
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-primary add-items-btn" data-variety-index="0">
                                                        <i class="fas fa-plus me-1"></i> Add Items
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Crop Type</label>
                                                <select class="form-control form-select variety-crop-type" name="variety_crop_type[0]" required>
                                                    <option value="vegetables">Vegetables</option>
                                                    <option value="fruits">Fruits</option>
                                                    <option value="cereals">Cereals</option>
                                                    <option value="flowers">Flowers</option>
                                                    <option value="pasture">Pasture</option>
                                                    <option value="oranges">Oranges</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Variety Name *</label>
                                                <input type="text" class="form-control variety-name" name="variety_name[0]" 
                                                       placeholder="e.g., Tomato, Maize, Orange" required maxlength="100">
                                            </div>
                                            
                                            <!-- Parameters Section -->
                                            <div class="parameters-section">
                                                <h6 class="mb-3">Agronomic Parameters</h6>
                                                <div class="parameter-container" data-parameter-index="0">
                                                    <div class="parameter-row">
                                                        <div class="form-group">
                                                            <label class="form-label small">Row Spacing (m)</label>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="variety_parameters[0][0][row_spacing]" 
                                                                   step="0.01" value="0.3" min="0.1" max="10">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label small">Plant Spacing (m)</label>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="variety_parameters[0][0][plant_spacing]" 
                                                                   step="0.01" value="0.2" min="0.1" max="10">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label small">Water Pressure (Bar)</label>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="variety_parameters[0][0][water_pressure]" 
                                                                   step="0.1" value="2.0" min="0.5" max="10">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label small">Notes</label>
                                                            <input type="text" class="form-control form-control-sm" 
                                                                   name="variety_parameters[0][0][notes]" 
                                                                   placeholder="Optional notes">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Items Section -->
                                            <div class="items-section">
                                                <h6 class="mb-3">Materials & Components</h6>
                                                <div class="table-responsive">
                                                    <table class="items-table">
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
                                                        <tbody class="items-table-body" data-variety-index="0">
                                                            <!-- Items will be added here -->
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-primary mt-2 add-item-row-btn" data-variety-index="0">
                                                    <i class="fas fa-plus"></i> Add Item
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-3">
                                        <button type="button" class="btn btn-outline-primary" id="addStandardVarietyBtn">
                                            <i class="fas fa-plus me-1"></i> Add Another Variety
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Settings Tab -->
                            <div class="tab-pane fade" id="settings" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">System Efficiency (%)</label>
                                            <input type="number" class="form-control" name="system_efficiency" 
                                                   min="50" max="95" value="85">
                                        </div>
                                    </div>
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
                <div class="modal-footer" style="flex-shrink: 0;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_template" class="btn btn-primary" id="submitTemplateBtn">
                        <i class="fas fa-save me-1"></i> Create Template
                    </button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Use Template Modal -->
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
                        
                        <div id="hybridInfo" style="display: none;"></div>
                        
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
                        <button type="submit" class="btn btn-primary" id="submitQuotationBtn">
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
            
            // Initialize hybrid configuration
            setupHybridConfiguration();
            
            // Initialize standard varieties
            setupStandardVarieties();
            
            // Setup form validation fix
            setupFormValidationFix();
        });
        
        // HYBRID CONFIGURATION FUNCTIONS
        let varietyCounter = 0;
        let parameterCounter = {};
        let itemCounter = {};
        
        function setupHybridConfiguration() {
            // Initial toggle based on current selection
            const irrigationType = document.getElementById('irrigationType').value;
            const isHybrid = irrigationType === 'hybrid';
            
            // Set initial required attributes
            if (!isHybrid) {
                const hybridFields = document.querySelectorAll('#hybridConfigSection [required]');
                hybridFields.forEach(field => {
                    field.removeAttribute('required');
                });
            }
            
            toggleHybridFields();
            
            // Add event listener for irrigation type changes
            const irrigationTypeSelect = document.getElementById('irrigationType');
            if (irrigationTypeSelect) {
                irrigationTypeSelect.addEventListener('change', toggleHybridFields);
            }
            
            // Add variety button
            const addVarietyBtn = document.getElementById('addVarietyBtn');
            if (addVarietyBtn) {
                addVarietyBtn.addEventListener('click', addHybridVariety);
            }
        }
        
        function toggleHybridFields() {
            const irrigationType = document.getElementById('irrigationType').value;
            const hybridSection = document.getElementById('hybridConfigSection');
            const standardSection = document.getElementById('standardCropSection');
            const isHybrid = irrigationType === 'hybrid';
            
            if (hybridSection) {
                hybridSection.style.display = isHybrid ? 'block' : 'none';
            }
            
            if (standardSection) {
                standardSection.style.display = isHybrid ? 'none' : 'block';
            }
            
            // Fix: Toggle required attribute based on visibility
            if (hybridSection) {
                const requiredFields = hybridSection.querySelectorAll('[required]');
                requiredFields.forEach(field => {
                    if (isHybrid) {
                        field.setAttribute('required', 'required');
                    } else {
                        field.removeAttribute('required');
                    }
                });
            }
            
            // Fix: Also handle standard section
            if (standardSection) {
                const requiredFields = standardSection.querySelectorAll('[required]');
                requiredFields.forEach(field => {
                    if (!isHybrid) {
                        field.setAttribute('required', 'required');
                    } else {
                        field.removeAttribute('required');
                    }
                });
            }
            
            // Add default variety if hybrid is selected and no varieties exist
            if (isHybrid && document.getElementById('varietiesContainer').children.length === 0) {
                addHybridVariety();
            }
        }
        
        function addHybridVariety() {
            varietyCounter++;
            const varietyIndex = varietyCounter;
            parameterCounter[varietyIndex] = 0;
            itemCounter[varietyIndex] = 0;
            
            const varietyDiv = document.createElement('div');
            varietyDiv.className = 'variety-section';
            varietyDiv.setAttribute('data-variety-index', varietyIndex);
            
            varietyDiv.innerHTML = `
                <div class="variety-header">
                    <div>
                        <span class="variety-number">${varietyCounter}</span>
                        <span class="variety-title">Crop Variety Configuration</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-success add-parameter-btn" data-variety-index="${varietyIndex}">
                            <i class="fas fa-plus me-1"></i> Add Parameters
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary add-items-btn" data-variety-index="${varietyIndex}">
                            <i class="fas fa-plus me-1"></i> Add Items
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-variety-btn" data-variety-index="${varietyIndex}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Crop Type *</label>
                            <select class="form-control form-select variety-crop-type" name="variety_crop_type[${varietyIndex}]" required>
                                <option value="vegetables">Vegetables</option>
                                <option value="fruits">Fruits</option>
                                <option value="cereals">Cereals</option>
                                <option value="flowers">Flowers</option>
                                <option value="pasture">Pasture</option>
                                <option value="oranges">Oranges</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Irrigation Method *</label>
                            <select class="form-control form-select variety-irrigation-method" name="variety_irrigation_method[${varietyIndex}]" required>
                                <option value="drip">Drip</option>
                                <option value="sprinkler">Sprinkler</option>
                                <option value="rain_hose">Rain Hose</option>
                                <option value="button_drip">Button Drip</option>
                                <option value="pop_up">Pop-up Sprinkler</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Variety Name *</label>
                            <input type="text" class="form-control variety-name" name="variety_name[${varietyIndex}]" 
                                   placeholder="e.g., Tomato, Maize, Orange" required maxlength="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Area Percentage (%)</label>
                            <input type="number" class="form-control area-percentage" 
                                   name="variety_area_percentage[${varietyIndex}]" 
                                   step="0.01" min="0" max="100" value="100" required>
                        </div>
                    </div>
                </div>
                
                <!-- Parameters Section -->
                <div class="parameters-section">
                    <h6 class="mb-3">Agronomic & Irrigation Parameters</h6>
                    <div class="parameter-container" data-parameter-index="0">
                        <div class="parameter-row">
                            <div class="form-group">
                                <label class="form-label small">Row Spacing (m)</label>
                                <input type="number" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][row_spacing]" 
                                       step="0.01" value="0.3" min="0.1" max="10">
                            </div>
                            <div class="form-group">
                                <label class="form-label small">Plant Spacing (m)</label>
                                <input type="number" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][plant_spacing]" 
                                       step="0.01" value="0.2" min="0.1" max="10">
                            </div>
                            <div class="form-group">
                                <label class="form-label small">Water Pressure (Bar)</label>
                                <input type="number" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][water_pressure]" 
                                       step="0.1" value="2.0" min="0.5" max="10">
                            </div>
                            <div class="form-group">
                                <label class="form-label small">Notes</label>
                                <input type="text" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][notes]" 
                                       placeholder="Optional notes">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Items Section -->
                <div class="items-section">
                    <h6 class="mb-3">Materials & Components</h6>
                    <div class="table-responsive">
                        <table class="items-table">
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
                            <tbody class="items-table-body" data-variety-index="${varietyIndex}">
                                <!-- Items will be added here -->
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2 add-item-row-btn" data-variety-index="${varietyIndex}">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
            `;
            
            document.getElementById('varietiesContainer').appendChild(varietyDiv);
            
            // Add event listeners for this variety
            setupVarietyEventListeners(varietyIndex);
            
            // Update area calculation
            updateAreaTotal();
            
            // Scroll to the newly added variety
            setTimeout(() => {
                varietyDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
            
            return varietyIndex;
        }
        
        function setupVarietyEventListeners(varietyIndex) {
            // Add parameter button
            const addParamBtn = document.querySelector(`.add-parameter-btn[data-variety-index="${varietyIndex}"]`);
            if (addParamBtn) {
                addParamBtn.addEventListener('click', function() {
                    addParameterRow(varietyIndex);
                });
            }
            
            // Add items button
            const addItemsBtn = document.querySelector(`.add-items-btn[data-variety-index="${varietyIndex}"]`);
            if (addItemsBtn) {
                addItemsBtn.addEventListener('click', function() {
                    addItemRow(varietyIndex);
                });
            }
            
            // Remove variety button
            const removeBtn = document.querySelector(`.remove-variety-btn[data-variety-index="${varietyIndex}"]`);
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    removeVariety(varietyIndex);
                });
            }
            
            // Area percentage input
            const areaInput = document.querySelector(`.area-percentage[name="variety_area_percentage[${varietyIndex}]"]`);
            if (areaInput) {
                areaInput.addEventListener('input', updateAreaTotal);
            }
            
            // Add item row button
            const addItemRowBtn = document.querySelector(`.add-item-row-btn[data-variety-index="${varietyIndex}"]`);
            if (addItemRowBtn) {
                addItemRowBtn.addEventListener('click', function() {
                    addItemRow(varietyIndex);
                });
            }
        }
        
        function addParameterRow(varietyIndex) {
            if (!parameterCounter[varietyIndex]) {
                parameterCounter[varietyIndex] = 0;
            }
            parameterCounter[varietyIndex]++;
            const paramIndex = parameterCounter[varietyIndex];
            
            const paramContainer = document.querySelector(`.variety-section[data-variety-index="${varietyIndex}"] .parameter-container`);
            if (!paramContainer) return;
            
            const paramRow = document.createElement('div');
            paramRow.className = 'parameter-container';
            paramRow.setAttribute('data-parameter-index', paramIndex);
            
            paramRow.innerHTML = `
                <div class="parameter-row">
                    <div class="form-group">
                        <label class="form-label small">Row Spacing (m)</label>
                        <input type="number" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][row_spacing]" 
                               step="0.01" value="0.3" min="0.1" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">Plant Spacing (m)</label>
                        <input type="number" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][plant_spacing]" 
                               step="0.01" value="0.2" min="0.1" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">Water Pressure (Bar)</label>
                        <input type="number" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][water_pressure]" 
                               step="0.1" value="2.0" min="0.5" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">Notes</label>
                        <input type="text" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][notes]" 
                               placeholder="Optional notes">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-parameter-btn" 
                                data-variety-index="${varietyIndex}" data-parameter-index="${paramIndex}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            
            paramContainer.parentNode.insertBefore(paramRow, paramContainer.nextSibling);
            
            // Add event listener for remove button
            const removeBtn = paramRow.querySelector('.remove-parameter-btn');
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    removeParameterRow(varietyIndex, paramIndex);
                });
            }
        }
        
        function removeParameterRow(varietyIndex, paramIndex) {
            const paramRow = document.querySelector(`.variety-section[data-variety-index="${varietyIndex}"] .parameter-container[data-parameter-index="${paramIndex}"]`);
            if (paramRow) {
                paramRow.remove();
            }
        }
        
        function addItemRow(varietyIndex) {
            if (!itemCounter[varietyIndex]) {
                itemCounter[varietyIndex] = 0;
            }
            itemCounter[varietyIndex]++;
            const itemIndex = itemCounter[varietyIndex];
            
            const itemsBody = document.querySelector(`.items-table-body[data-variety-index="${varietyIndex}"]`);
            if (!itemsBody) return;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="text" class="form-control form-control-sm item-description" 
                           name="variety_items[${varietyIndex}][${itemIndex}][description]" 
                           placeholder="Item description" required maxlength="255">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm item-units" 
                           name="variety_items[${varietyIndex}][${itemIndex}][units]" 
                           placeholder="e.g., M/Roll" required maxlength="50">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm item-quantity" 
                           name="variety_items[${varietyIndex}][${itemIndex}][quantity]" 
                           step="0.01" min="0" value="1" required max="1000000">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm item-rate" 
                           name="variety_items[${varietyIndex}][${itemIndex}][rate]" 
                           step="0.01" min="0" value="0" required max="10000000">
                </td>
                <td class="item-amount fw-bold">0.00</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            `;
            
            itemsBody.appendChild(row);
            
            // Add event listeners for this item
            const quantityInput = row.querySelector('.item-quantity');
            const rateInput = row.querySelector('.item-rate');
            const removeBtn = row.querySelector('.remove-item-btn');
            
            if (quantityInput && rateInput) {
                quantityInput.addEventListener('input', function() {
                    calculateItemAmount(row);
                    updateVarietyTotal(varietyIndex);
                });
                rateInput.addEventListener('input', function() {
                    calculateItemAmount(row);
                    updateVarietyTotal(varietyIndex);
                });
                
                // Trigger initial calculation
                calculateItemAmount(row);
            }
            
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    row.remove();
                    updateVarietyTotal(varietyIndex);
                });
            }
        }
        
        function calculateItemAmount(row) {
            const quantityInput = row.querySelector('.item-quantity');
            const rateInput = row.querySelector('.item-rate');
            const amountCell = row.querySelector('.item-amount');
            
            if (!quantityInput || !rateInput || !amountCell) return;
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            const amount = quantity * rate;
            amountCell.textContent = amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        
        function updateVarietyTotal(varietyIndex) {
            let total = 0;
            const itemsBody = document.querySelector(`.items-table-body[data-variety-index="${varietyIndex}"]`);
            if (itemsBody) {
                itemsBody.querySelectorAll('.item-amount').forEach(cell => {
                    const amountText = cell.textContent.replace(/,/g, '');
                    const amount = parseFloat(amountText) || 0;
                    total += amount;
                });
            }
            return total;
        }
        
        function removeVariety(varietyIndex) {
            const varietySection = document.querySelector(`.variety-section[data-variety-index="${varietyIndex}"]`);
            if (varietySection) {
                varietySection.remove();
                varietyCounter--;
                
                // Update variety numbers
                updateVarietyNumbers();
                
                // Update area calculation
                updateAreaTotal();
            }
        }
        
        function updateVarietyNumbers() {
            const varietySections = document.querySelectorAll('#varietiesContainer .variety-section');
            varietySections.forEach((section, index) => {
                const numberSpan = section.querySelector('.variety-number');
                if (numberSpan) {
                    numberSpan.textContent = index + 1;
                }
            });
        }
        
        function updateAreaTotal() {
            let total = 0;
            const areaInputs = document.querySelectorAll('.area-percentage');
            areaInputs.forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            
            const warning = document.getElementById('areaWarning');
            const currentTotal = document.getElementById('currentTotal');
            
            if (currentTotal) {
                currentTotal.textContent = `(Current: ${total.toFixed(2)}%)`;
            }
            
            if (warning) {
                warning.style.display = Math.abs(total - 100) > 0.01 ? 'block' : 'none';
            }
            
            return total;
        }
        
        // STANDARD VARIETIES FUNCTIONS
        let standardVarietyCounter = 0;
        let standardParameterCounter = {};
        let standardItemCounter = {};
        
        function setupStandardVarieties() {
            // Initialize counters for first variety
            standardVarietyCounter = 0;
            standardParameterCounter[0] = 0;
            standardItemCounter[0] = 0;
            
            // Add standard variety button
            const addStandardVarietyBtn = document.getElementById('addStandardVarietyBtn');
            if (addStandardVarietyBtn) {
                addStandardVarietyBtn.addEventListener('click', addStandardVariety);
            }
            
            // Setup event listeners for first variety
            setupStandardVarietyEventListeners(0);
        }
        
        function addStandardVariety() {
            standardVarietyCounter++;
            const varietyIndex = standardVarietyCounter;
            standardParameterCounter[varietyIndex] = 0;
            standardItemCounter[varietyIndex] = 0;
            
            const varietyDiv = document.createElement('div');
            varietyDiv.className = 'variety-section';
            varietyDiv.setAttribute('data-variety-index', varietyIndex);
            
            varietyDiv.innerHTML = `
                <div class="variety-header">
                    <div>
                        <span class="variety-number">${standardVarietyCounter + 1}</span>
                        <span class="variety-title">Crop Variety</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-success add-parameter-btn" data-variety-index="${varietyIndex}">
                            <i class="fas fa-plus me-1"></i> Add Parameters
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary add-items-btn" data-variety-index="${varietyIndex}">
                            <i class="fas fa-plus me-1"></i> Add Items
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-variety-btn" data-variety-index="${varietyIndex}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Crop Type</label>
                    <select class="form-control form-select variety-crop-type" name="variety_crop_type[${varietyIndex}]" required>
                        <option value="vegetables">Vegetables</option>
                        <option value="fruits">Fruits</option>
                        <option value="cereals">Cereals</option>
                        <option value="flowers">Flowers</option>
                        <option value="pasture">Pasture</option>
                        <option value="oranges">Oranges</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Variety Name *</label>
                    <input type="text" class="form-control variety-name" name="variety_name[${varietyIndex}]" 
                           placeholder="e.g., Tomato, Maize, Orange" required maxlength="100">
                </div>
                
                <!-- Parameters Section -->
                <div class="parameters-section">
                    <h6 class="mb-3">Agronomic Parameters</h6>
                    <div class="parameter-container" data-parameter-index="0">
                        <div class="parameter-row">
                            <div class="form-group">
                                <label class="form-label small">Row Spacing (m)</label>
                                <input type="number" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][row_spacing]" 
                                       step="0.01" value="0.3" min="0.1" max="10">
                            </div>
                            <div class="form-group">
                                <label class="form-label small">Plant Spacing (m)</label>
                                <input type="number" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][plant_spacing]" 
                                       step="0.01" value="0.2" min="0.1" max="10">
                            </div>
                            <div class="form-group">
                                <label class="form-label small">Water Pressure (Bar)</label>
                                <input type="number" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][water_pressure]" 
                                       step="0.1" value="2.0" min="0.5" max="10">
                            </div>
                            <div class="form-group">
                                <label class="form-label small">Notes</label>
                                <input type="text" class="form-control form-control-sm" 
                                       name="variety_parameters[${varietyIndex}][0][notes]" 
                                       placeholder="Optional notes">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Items Section -->
                <div class="items-section">
                    <h6 class="mb-3">Materials & Components</h6>
                    <div class="table-responsive">
                        <table class="items-table">
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
                            <tbody class="items-table-body" data-variety-index="${varietyIndex}">
                                <!-- Items will be added here -->
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2 add-item-row-btn" data-variety-index="${varietyIndex}">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
            `;
            
            document.getElementById('standardVarietiesContainer').appendChild(varietyDiv);
            
            // Setup event listeners for this variety
            setupStandardVarietyEventListeners(varietyIndex);
            
            // Scroll to the newly added variety
            setTimeout(() => {
                varietyDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
            
            return varietyIndex;
        }
        
        function setupStandardVarietyEventListeners(varietyIndex) {
            // Similar to hybrid setup but without area percentage
            const addParamBtn = document.querySelector(`#standardVarietiesContainer .add-parameter-btn[data-variety-index="${varietyIndex}"]`);
            if (addParamBtn) {
                addParamBtn.addEventListener('click', function() {
                    addStandardParameterRow(varietyIndex);
                });
            }
            
            const addItemsBtn = document.querySelector(`#standardVarietiesContainer .add-items-btn[data-variety-index="${varietyIndex}"]`);
            if (addItemsBtn) {
                addItemsBtn.addEventListener('click', function() {
                    addStandardItemRow(varietyIndex);
                });
            }
            
            const removeBtn = document.querySelector(`#standardVarietiesContainer .remove-variety-btn[data-variety-index="${varietyIndex}"]`);
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    removeStandardVariety(varietyIndex);
                });
            }
            
            const addItemRowBtn = document.querySelector(`#standardVarietiesContainer .add-item-row-btn[data-variety-index="${varietyIndex}"]`);
            if (addItemRowBtn) {
                addItemRowBtn.addEventListener('click', function() {
                    addStandardItemRow(varietyIndex);
                });
            }
        }
        
        function addStandardParameterRow(varietyIndex) {
            if (!standardParameterCounter[varietyIndex]) {
                standardParameterCounter[varietyIndex] = 0;
            }
            standardParameterCounter[varietyIndex]++;
            const paramIndex = standardParameterCounter[varietyIndex];
            
            const paramContainer = document.querySelector(`#standardVarietiesContainer .variety-section[data-variety-index="${varietyIndex}"] .parameter-container`);
            if (!paramContainer) return;
            
            const paramRow = document.createElement('div');
            paramRow.className = 'parameter-container';
            paramRow.setAttribute('data-parameter-index', paramIndex);
            
            paramRow.innerHTML = `
                <div class="parameter-row">
                    <div class="form-group">
                        <label class="form-label small">Row Spacing (m)</label>
                        <input type="number" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][row_spacing]" 
                               step="0.01" value="0.3" min="0.1" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">Plant Spacing (m)</label>
                        <input type="number" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][plant_spacing]" 
                               step="0.01" value="0.2" min="0.1" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">Water Pressure (Bar)</label>
                        <input type="number" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][water_pressure]" 
                               step="0.1" value="2.0" min="0.5" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">Notes</label>
                        <input type="text" class="form-control form-control-sm" 
                               name="variety_parameters[${varietyIndex}][${paramIndex}][notes]" 
                               placeholder="Optional notes">
                    </div>
                    <div class="form-group">
                        <label class="form-label small">&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-parameter-btn" 
                                data-variety-index="${varietyIndex}" data-parameter-index="${paramIndex}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            
            paramContainer.parentNode.insertBefore(paramRow, paramContainer.nextSibling);
            
            // Add event listener for remove button
            const removeBtn = paramRow.querySelector('.remove-parameter-btn');
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    removeStandardParameterRow(varietyIndex, paramIndex);
                });
            }
        }
        
        function removeStandardParameterRow(varietyIndex, paramIndex) {
            const paramRow = document.querySelector(`#standardVarietiesContainer .variety-section[data-variety-index="${varietyIndex}"] .parameter-container[data-parameter-index="${paramIndex}"]`);
            if (paramRow) {
                paramRow.remove();
            }
        }
        
        function addStandardItemRow(varietyIndex) {
            if (!standardItemCounter[varietyIndex]) {
                standardItemCounter[varietyIndex] = 0;
            }
            standardItemCounter[varietyIndex]++;
            const itemIndex = standardItemCounter[varietyIndex];
            
            const itemsBody = document.querySelector(`#standardVarietiesContainer .items-table-body[data-variety-index="${varietyIndex}"]`);
            if (!itemsBody) return;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="text" class="form-control form-control-sm item-description" 
                           name="variety_items[${varietyIndex}][${itemIndex}][description]" 
                           placeholder="Item description" required maxlength="255">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm item-units" 
                           name="variety_items[${varietyIndex}][${itemIndex}][units]" 
                           placeholder="e.g., M/Roll" required maxlength="50">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm item-quantity" 
                           name="variety_items[${varietyIndex}][${itemIndex}][quantity]" 
                           step="0.01" min="0" value="1" required max="1000000">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm item-rate" 
                           name="variety_items[${varietyIndex}][${itemIndex}][rate]" 
                           step="0.01" min="0" value="0" required max="10000000">
                </td>
                <td class="item-amount fw-bold">0.00</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            `;
            
            itemsBody.appendChild(row);
            
            // Add event listeners for this item
            const quantityInput = row.querySelector('.item-quantity');
            const rateInput = row.querySelector('.item-rate');
            const removeBtn = row.querySelector('.remove-item-btn');
            
            if (quantityInput && rateInput) {
                quantityInput.addEventListener('input', function() {
                    calculateStandardItemAmount(row);
                    updateStandardVarietyTotal(varietyIndex);
                });
                rateInput.addEventListener('input', function() {
                    calculateStandardItemAmount(row);
                    updateStandardVarietyTotal(varietyIndex);
                });
                
                // Trigger initial calculation
                calculateStandardItemAmount(row);
            }
            
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    row.remove();
                    updateStandardVarietyTotal(varietyIndex);
                });
            }
        }
        
        function calculateStandardItemAmount(row) {
            calculateItemAmount(row); // Reuse the same function
        }
        
        function updateStandardVarietyTotal(varietyIndex) {
            return updateVarietyTotal(varietyIndex); // Reuse the same function
        }
        
        function removeStandardVariety(varietyIndex) {
            const varietySection = document.querySelector(`#standardVarietiesContainer .variety-section[data-variety-index="${varietyIndex}"]`);
            if (varietySection) {
                varietySection.remove();
                standardVarietyCounter--;
                
                // Update variety numbers in standard section
                updateStandardVarietyNumbers();
            }
        }
        
        function updateStandardVarietyNumbers() {
            const varietySections = document.querySelectorAll('#standardVarietiesContainer .variety-section');
            varietySections.forEach((section, index) => {
                const numberSpan = section.querySelector('.variety-number');
                if (numberSpan) {
                    numberSpan.textContent = index + 1;
                }
            });
        }
        
        // FORM VALIDATION FIX
        function setupFormValidationFix() {
            const templateForm = document.getElementById('templateForm');
            const submitBtn = document.getElementById('submitTemplateBtn');
            
            if (templateForm && submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    // Get the form
                    const form = document.getElementById('templateForm');
                    
                    // Check which section is visible
                    const irrigationType = document.getElementById('irrigationType').value;
                    const isHybrid = irrigationType === 'hybrid';
                    
                    // Remove required attribute from hidden fields
                    if (!isHybrid) {
                        // Hide hybrid section completely during validation
                        const hybridSection = document.getElementById('hybridConfigSection');
                        if (hybridSection) {
                            const hybridRequiredFields = hybridSection.querySelectorAll('[required]');
                            hybridRequiredFields.forEach(field => {
                                field.removeAttribute('required');
                            });
                        }
                    } else {
                        // Hide standard section
                        const standardSection = document.getElementById('standardCropSection');
                        if (standardSection) {
                            const standardRequiredFields = standardSection.querySelectorAll('[required]');
                            standardRequiredFields.forEach(field => {
                                field.removeAttribute('required');
                            });
                        }
                    }
                    
                    // Check basic required fields
                    const templateName = document.querySelector('input[name="template_name"]');
                    const landSize = document.querySelector('input[name="land_size"]');
                    
                    if (!templateName || !templateName.value.trim()) {
                        e.preventDefault();
                        alert('Template name is required');
                        templateName.focus();
                        return false;
                    }
                    
                    if (!landSize || !landSize.value || parseFloat(landSize.value) <= 0) {
                        e.preventDefault();
                        alert('Land size must be greater than 0');
                        landSize.focus();
                        return false;
                    }
                    
                    // Validate hybrid configuration
                    if (isHybrid) {
                        const totalArea = updateAreaTotal();
                        if (Math.abs(totalArea - 100) > 0.01) {
                            e.preventDefault();
                            alert('Total area percentage for hybrid system must equal 100%');
                            document.getElementById('areaWarning').scrollIntoView({ behavior: 'smooth' });
                            return false;
                        }
                        
                        // Validate that at least one variety has items
                        const hasItems = document.querySelectorAll('#varietiesContainer .items-table-body tr').length > 0;
                        if (!hasItems) {
                            e.preventDefault();
                            alert('Please add at least one item to a crop variety');
                            document.getElementById('crops-tab').click();
                            return false;
                        }
                        
                        // Validate all variety names in hybrid section
                        const hybridNameFields = document.querySelectorAll('#hybridConfigSection .variety-name');
                        let hasValidHybridFields = true;
                        
                        hybridNameFields.forEach(field => {
                            if (!field.value.trim()) {
                                hasValidHybridFields = false;
                                field.focus();
                                alert('Please fill in all required variety names in the hybrid section');
                                e.preventDefault();
                                return false;
                            }
                        });
                        
                        if (!hasValidHybridFields) {
                            return false;
                        }
                    } else {
                        // Validate standard section fields
                        const standardNameFields = document.querySelectorAll('#standardCropSection .variety-name');
                        let hasValidStandardFields = true;
                        
                        standardNameFields.forEach(field => {
                            if (!field.value.trim()) {
                                hasValidStandardFields = false;
                                field.focus();
                                alert('Please fill in all required variety names in the standard section');
                                e.preventDefault();
                                return false;
                            }
                        });
                        
                        if (!hasValidStandardFields) {
                            return false;
                        }
                    }
                    
                    // If all validations pass, submit the form
                    return true;
                });
            }
        }
        
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
        
        // Use Template Modal functionality
        const useTemplateModal = document.getElementById('useTemplateModal');
        if (useTemplateModal) {
            useTemplateModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const templateId = button.getAttribute('data-template-id');
                const templateName = button.getAttribute('data-template-name');
                const isHybrid = button.getAttribute('data-is-hybrid') === '1';
                const varietyCount = button.getAttribute('data-variety-count') || 0;
                
                const templateIdInput = document.getElementById('useTemplateId');
                const templateNameSpan = document.getElementById('useTemplateName');
                const hybridInfoDiv = document.getElementById('hybridInfo');
                
                if (templateIdInput) templateIdInput.value = templateId;
                if (templateNameSpan) templateNameSpan.textContent = templateName;
                
                // Show hybrid info if applicable
                if (hybridInfoDiv) {
                    if (isHybrid) {
                        hybridInfoDiv.innerHTML = `
                            <div class="alert alert-info mt-2">
                                <i class="fas fa-random me-2"></i>
                                <strong>Hybrid System:</strong> This template includes multiple crop varieties with different irrigation methods.
                                <div class="mt-1 small">
                                    <strong>Varieties:</strong> ${varietyCount} crop variet${varietyCount != 1 ? 'ies' : 'y'}
                                </div>
                            </div>
                        `;
                        hybridInfoDiv.style.display = 'block';
                    } else {
                        hybridInfoDiv.style.display = 'none';
                    }
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
        
        // View template function
        function viewTemplate(templateId) {
            window.location.href = `view_template.php?id=${templateId}`;
        }
        
        // View quotation function
        function viewQuotation(quotationId) {
            window.location.href = `view_quotation.php?id=${quotationId}`;
        }
        
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