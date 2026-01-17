<?php
// test_templates_flow.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'config.php';

// Simulate your actual user session
$_SESSION['user_id'] = 4;

$database = new Database();
$conn = $database->getConnection();

echo "<!DOCTYPE html><html><head><title>Template Flow Test</title><style>
    body { font-family: Arial; margin: 20px; }
    .section { background: #f5f5f5; padding: 15px; margin: 10px 0; border-radius: 5px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    pre { background: #eee; padding: 10px; border-radius: 3px; overflow: auto; }
</style></head><body>";

echo "<h1>Testing templates.php Form Submission Flow</h1>";

// Simulate the exact POST data your form sends
echo "<div class='section'>";
echo "<h2>1. Simulating Form POST Data</h2>";

$post_data = [
    'generate_from_template' => '1',
    'template_id' => '2',
    'quotation_number' => 'QTN-' . date('Ymd-His'),
    'project_name' => 'Test Project From Debug',
    'customer_name' => 'Test Customer',
    'location' => 'Test Location',
    'land_size' => '2.5',
    'save_quotation' => '1'
];

echo "<pre>POST data that would be sent:\n" . print_r($post_data, true) . "</pre>";

// Get active period
$stmt = $conn->prepare("SELECT * FROM time_periods WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$active_period = $result->fetch_assoc();
$stmt->close();

echo "<div class='info'>Active Period ID: " . ($active_period['id'] ?? 'N/A') . "</div>";
echo "<div class='info'>User ID: " . $_SESSION['user_id'] . "</div>";
echo "</div>";

// Test the generateQuotationFromTemplate function
echo "<div class='section'>";
echo "<h2>2. Testing generateQuotationFromTemplate</h2>";

// Copy the exact function from your templates.php
function generateQuotationFromTemplate($template_id, $data, $conn) {
    $template_stmt = $conn->prepare("SELECT * FROM irrigation_templates WHERE id = ?");
    $template_stmt->bind_param("i", $template_id);
    $template_stmt->execute();
    $result = $template_stmt->get_result();
    $template = $result->fetch_assoc();
    $template_stmt->close();
    
    if (!$template) {
        return null;
    }
    
    // Decode JSON fields
    $template['items'] = json_decode($template['items_json'] ?? '[]', true) ?? [];
    
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

// Call the function with our test data
$generated_quotation = generateQuotationFromTemplate(2, $post_data, $conn);

if ($generated_quotation) {
    echo "<div class='success'>✓ Quotation generated successfully!</div>";
    echo "<h3>Generated Quotation Data:</h3>";
    echo "<pre>" . print_r($generated_quotation, true) . "</pre>";
} else {
    echo "<div class='error'>✗ Failed to generate quotation</div>";
}
echo "</div>";

// Test saveQuotationToDatabase with the generated data
echo "<div class='section'>";
echo "<h2>3. Testing saveQuotationToDatabase with Generated Data</h2>";

if ($generated_quotation) {
    // Add template_id to the quotation
    $generated_quotation['template_id'] = 2;
    
    // Test the save function
    function testSaveQuotation($quotation, $user_id, $period_id, $conn) {
        try {
            // Insert quotation
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
            
            $items_json = json_encode($quotation['items'] ?? []);
            $template_id = isset($quotation['template_id']) ? intval($quotation['template_id']) : null;
            
            // Extract values
            $quotation_number = $quotation['quotation_number'] ?? '';
            $project_name = $quotation['project_name'] ?? '';
            $customer_name = $quotation['customer_name'] ?? '';
            $location = $quotation['location'] ?? '';
            $land_size = floatval($quotation['land_size'] ?? 0);
            $land_unit = $quotation['land_unit'] ?? 'acres';
            $crop_type = $quotation['crop_type'] ?? '';
            $irrigation_type = $quotation['irrigation_type'] ?? 'drip';
            $total_material = floatval($quotation['total_material'] ?? 0);
            $labor_cost = floatval($quotation['labor_cost'] ?? 0);
            $discount_amount = floatval($quotation['discount_amount'] ?? 0);
            $tax_amount = floatval($quotation['tax_amount'] ?? 0);
            $grand_total = floatval($quotation['grand_total'] ?? 0);
            
            // Bind parameters
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
            
            return $quotation_id;
        } catch (Exception $e) {
            error_log("Error saving quotation: " . $e->getMessage());
            return false;
        }
    }
    
    $saved_id = testSaveQuotation($generated_quotation, $_SESSION['user_id'], $active_period['id'], $conn);
    
    if ($saved_id) {
        echo "<div class='success'>✓ Quotation saved successfully! ID: $saved_id</div>";
        
        // Verify it was saved to the CORRECT table
        $verify_stmt = $conn->prepare("SELECT * FROM irrigation_quotations WHERE id = ?");
        $verify_stmt->bind_param("i", $saved_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $saved_quotation = $verify_result->fetch_assoc();
        $verify_stmt->close();
        
        echo "<h3>Saved to irrigation_quotations table:</h3>";
        echo "<table border='1' cellpadding='5'>";
        foreach ($saved_quotation as $key => $value) {
            echo "<tr><td><strong>$key</strong></td><td>";
            if ($key === 'items_json') {
                echo "<pre>" . print_r(json_decode($value, true), true) . "</pre>";
            } else {
                echo htmlspecialchars($value ?? '');
            }
            echo "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>✗ Failed to save quotation</div>";
    }
}
echo "</div>";

// Check what happens in your actual templates.php
echo "<div class='section'>";
echo "<h2>4. The ACTUAL Issue in Your templates.php</h2>";

echo "<h3>Look at this code in your templates.php (around line 430-450):</h3>";
echo "<pre style='background:#ffcccc;'>";
echo htmlspecialchars('
// Handle quotation generation from template
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'generate_from_template\'])) {
    $template_id = $_POST[\'template_id\'] ?? null;
    $quotation_data = $_POST;
    
    // Generate quotation from template
    $quotation = generateQuotationFromTemplate($template_id, $quotation_data, $conn);
    
    if ($quotation && isset($_POST[\'save_quotation\'])) {
        $quotation[\'template_id\'] = $template_id;
        $saved = saveQuotationToDatabase($quotation, $user_id, $active_period[\'id\'] ?? 1, $conn);
        if ($saved) {
            $_SESSION[\'success_message\'] = "Quotation generated and saved!";
        } else {
            $_SESSION[\'error_message\'] = "Failed to save quotation.";
        }
        header("Location: " . $_SERVER[\'PHP_SELF\']);
        exit();
    }
}
');
echo "</pre>";

echo "<h3>Potential Issues:</h3>";
echo "<ol>";
echo "<li><strong>Missing Period:</strong> If \$active_period is not set, it uses 1 as default</li>";
echo "<li><strong>Wrong User ID:</strong> Make sure \$user_id is set from session</li>";
echo "<li><strong>Missing Redirect:</strong> What happens if save fails? No error is shown</li>";
echo "<li><strong>Session Messages:</strong> Might not be displayed properly</li>";
echo "</ol>";

echo "<h3>Suggested Fix:</h3>";
echo "<pre style='background:#ccffcc;'>";
echo htmlspecialchars('
// FIXED VERSION:
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'generate_from_template\'])) {
    $template_id = $_POST[\'template_id\'] ?? null;
    $quotation_data = $_POST;
    
    // Generate quotation from template
    $quotation = generateQuotationFromTemplate($template_id, $quotation_data, $conn);
    
    if ($quotation) {
        if (isset($_POST[\'save_quotation\'])) {
            // Make sure we have all required data
            if (!isset($active_period[\'id\'])) {
                // Try to get any period
                $stmt = $conn->prepare("SELECT id FROM time_periods LIMIT 1");
                $stmt->execute();
                $result = $stmt->get_result();
                $period = $result->fetch_assoc();
                $stmt->close();
                $period_id = $period[\'id\'] ?? 1;
            } else {
                $period_id = $active_period[\'id\'];
            }
            
            $quotation[\'template_id\'] = $template_id;
            $saved = saveQuotationToDatabase($quotation, $user_id, $period_id, $conn);
            
            if ($saved) {
                $_SESSION[\'success_message\'] = "Quotation generated and saved successfully!";
            } else {
                $_SESSION[\'error_message\'] = "Failed to save quotation. Please try again.";
            }
        } else {
            // Just generate without saving
            $_SESSION[\'quotation_data\'] = $quotation;
            $_SESSION[\'success_message\'] = "Quotation generated successfully!";
        }
    } else {
        $_SESSION[\'error_message\'] = "Failed to generate quotation. Template not found.";
    }
    
    header("Location: " . $_SERVER[\'PHP_SELF\']);
    exit();
}
');
echo "</pre>";
echo "</div>";

// Final test - simulate the exact flow
echo "<div class='section'>";
echo "<h2>5. Final Test - Simulate Entire Flow</h2>";

echo "<form method='POST' action='templates.php' style='border:2px solid green;padding:20px;'>";
echo "<h3>This is EXACTLY what your form sends:</h3>";
echo "<input type='hidden' name='generate_from_template' value='1'>";
echo "<input type='hidden' name='template_id' value='2'>";
echo "<input type='hidden' name='save_quotation' value='1'>";
echo "<div style='margin:10px 0;'>";
echo "<label><strong>quotation_number:</strong></label><br>";
echo "<input type='text' name='quotation_number' value='QTN-" . date('Ymd-His') . "' readonly style='width:300px;padding:5px;background:#f0f0f0;'>";
echo "</div>";
echo "<div style='margin:10px 0;'>";
echo "<label><strong>project_name:</strong> *</label><br>";
echo "<input type='text' name='project_name' value='Live Test Project' required style='width:300px;padding:5px;'>";
echo "</div>";
echo "<div style='margin:10px 0;'>";
echo "<label><strong>land_size:</strong></label><br>";
echo "<input type='number' name='land_size' value='3.0' step='0.01' style='width:150px;padding:5px;'>";
echo "</div>";
echo "<button type='submit' style='background:green;color:white;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-weight:bold;'>
    <i class='fas fa-paper-plane'></i> SUBMIT TO templates.php
</button>";
echo "<p><small>This will trigger the exact same code path as clicking 'Use' on a template</small></p>";
echo "</form>";
echo "</div>";

$conn->close();
echo "</body></html>";