<?php
// test_template_debug.php
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict'
]);

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

echo "<!DOCTYPE html>";
echo "<html lang='en'>";
echo "<head>";
echo "<title>Template Debug Test</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "<style>
    body { padding: 20px; }
    .debug-section { margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background-color: #f2f2f2; }
</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h1>Template Debug Test</h1>";
echo "<p class='text-muted'>This script will help diagnose the 'Template not found' issue</p>";

// Check session
echo "<div class='debug-section'>";
echo "<h3>1. Session Check</h3>";
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    echo "<p class='success'>✓ User ID in session: $user_id</p>";
} else {
    echo "<p class='error'>✗ No user ID in session. You might need to login first.</p>";
    echo "<p><a href='login.php' class='btn btn-primary'>Go to Login</a></p>";
    exit();
}
echo "</div>";

// Database connection test
echo "<div class='debug-section'>";
echo "<h3>2. Database Connection Test</h3>";
try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($conn->connect_error) {
        echo "<p class='error'>✗ Database connection failed: " . $conn->connect_error . "</p>";
    } else {
        echo "<p class='success'>✓ Database connection successful</p>";
        echo "<p>Database Host: " . (defined('DB_HOST') ? DB_HOST : 'Not defined') . "</p>";
        echo "<p>Database Name: " . (defined('DB_NAME') ? DB_NAME : 'Not defined') . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Database connection exception: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Check if we have a template ID
echo "<div class='debug-section'>";
echo "<h3>3. Template ID from URL</h3>";
if (isset($_GET['id'])) {
    $template_id = intval($_GET['id']);
    echo "<p class='success'>✓ Template ID from URL: $template_id</p>";
} else {
    echo "<p class='warning'>⚠ No template ID in URL. Testing with sample data...</p>";
    $template_id = 0;
}
echo "</div>";

// Check user existence
echo "<div class='debug-section'>";
echo "<h3>4. User Existence Check</h3>";
$user_check_sql = "SELECT id, name, email FROM users WHERE id = ?";
$stmt = $conn->prepare($user_check_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user) {
    echo "<p class='success'>✓ User found in database</p>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th></tr>";
    echo "<tr><td>{$user['id']}</td><td>{$user['username']}</td><td>{$user['email']}</td></tr>";
    echo "</table>";
} else {
    echo "<p class='error'>✗ User NOT found in database with ID: $user_id</p>";
    echo "<p>This could be a session issue. Try logging out and back in.</p>";
}
echo "</div>";

// Check if template exists at all
echo "<div class='debug-section'>";
echo "<h3>5. Template Existence (Any Template)</h3>";
$sql = "SELECT COUNT(*) as total_templates FROM irrigation_templates";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$total_templates = $row['total_templates'];

if ($total_templates > 0) {
    echo "<p class='success'>✓ Templates exist in database: $total_templates total templates</p>";
    
    // Show first 5 templates
    $sql = "SELECT id, template_name, user_id, is_active FROM irrigation_templates LIMIT 5";
    $result = $conn->query($sql);
    
    echo "<h5>Sample Templates (first 5):</h5>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Name</th><th>User ID</th><th>Active</th><th>Your Template?</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $is_yours = ($row['user_id'] == $user_id) ? 'Yes' : 'No';
        $active_status = $row['is_active'] ? 'Active' : 'Inactive';
        $row_class = ($row['user_id'] == $user_id) ? 'class="table-success"' : '';
        echo "<tr $row_class>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['template_name']}</td>";
        echo "<td>{$row['user_id']}</td>";
        echo "<td>$active_status</td>";
        echo "<td>$is_yours</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ NO templates found in database at all!</p>";
    echo "<p>This means the 'irrigation_templates' table is empty.</p>";
}
echo "</div>";

// Check user's templates
echo "<div class='debug-section'>";
echo "<h3>6. Your Templates</h3>";
$sql = "SELECT id, template_name, is_active, created_at FROM irrigation_templates WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_templates = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (count($user_templates) > 0) {
    echo "<p class='success'>✓ You have " . count($user_templates) . " template(s)</p>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Name</th><th>Active</th><th>Created</th><th>Test Link</th></tr>";
    foreach ($user_templates as $template) {
        $active_status = $template['is_active'] ? 'Active ✓' : 'Inactive ✗';
        $test_link = $template['is_active'] ? 
            "<a href='view_template.php?id={$template['id']}' class='btn btn-sm btn-primary'>Test View</a>" :
            "<span class='text-muted'>Inactive</span>";
        echo "<tr>";
        echo "<td>{$template['id']}</td>";
        echo "<td>{$template['template_name']}</td>";
        echo "<td>$active_status</td>";
        echo "<td>{$template['created_at']}</td>";
        echo "<td>$test_link</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>⚠ You have NO templates in the database</p>";
    echo "<p>You need to create templates first from the main page.</p>";
    echo "<p><a href='quotation.php' class='btn btn-primary'>Go Create Templates</a></p>";
}
echo "</div>";

// Specific template check (if ID provided)
if ($template_id > 0) {
    echo "<div class='debug-section'>";
    echo "<h3>7. Specific Template Check (ID: $template_id)</h3>";
    
    // Check without user restriction
    $sql = "SELECT * FROM irrigation_templates WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template_any = $result->fetch_assoc();
    $stmt->close();
    
    if ($template_any) {
        echo "<p class='success'>✓ Template EXISTS in database</p>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        echo "<tr><td>ID</td><td>{$template_any['id']}</td></tr>";
        echo "<tr><td>Name</td><td>{$template_any['template_name']}</td></tr>";
        echo "<tr><td>User ID</td><td>{$template_any['user_id']}</td></tr>";
        echo "<tr><td>Active</td><td>" . ($template_any['is_active'] ? 'Yes' : 'No') . "</td></tr>";
        echo "<tr><td>Your User ID</td><td>$user_id</td></tr>";
        echo "<tr><td>Match?</td><td>" . ($template_any['user_id'] == $user_id ? 'YES ✓' : 'NO ✗') . "</td></tr>";
        echo "</table>";
        
        // Now check with user restriction (like view_template.php does)
        $sql = "SELECT * FROM irrigation_templates WHERE id = ? AND user_id = ? AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $template_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $template_yours = $result->fetch_assoc();
        $stmt->close();
        
        if ($template_yours) {
            echo "<p class='success'>✓ Template found with user/permission check!</p>";
            echo "<p>This means view_template.php SHOULD work for this template.</p>";
        } else {
            echo "<p class='error'>✗ Template NOT found with user/permission check</p>";
            echo "<p>Possible reasons:</p>";
            echo "<ul>";
            if ($template_any['user_id'] != $user_id) {
                echo "<li>Template belongs to different user (User ID: {$template_any['user_id']})</li>";
            }
            if (!$template_any['is_active']) {
                echo "<li>Template is not active (is_active = 0)</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p class='error'>✗ Template ID $template_id does NOT exist in database at all</p>";
        echo "<p>The template may have been deleted or the ID is wrong.</p>";
    }
    echo "</div>";
}

// Table structure check
echo "<div class='debug-section'>";
echo "<h3>8. Table Structure Check</h3>";
$tables_to_check = ['irrigation_templates', 'template_crop_varieties', 'users'];
foreach ($tables_to_check as $table) {
    $sql = "SHOW TABLES LIKE '$table'";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        echo "<p class='success'>✓ Table '$table' exists</p>";
        
        // Show columns
        $columns_sql = "SHOW COLUMNS FROM $table";
        $columns_result = $conn->query($columns_sql);
        
        echo "<details>";
        echo "<summary>Show columns for $table</summary>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        while ($col = $columns_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</details>";
    } else {
        echo "<p class='error'>✗ Table '$table' does NOT exist!</p>";
    }
}
echo "</div>";

// Test the exact query from view_template.php
echo "<div class='debug-section'>";
echo "<h3>9. Test Exact Query from view_template.php</h3>";
if ($template_id > 0) {
    $test_sql = "SELECT it.* FROM irrigation_templates it WHERE it.id = ? AND it.user_id = ? AND it.is_active = 1";
    $stmt = $conn->prepare($test_sql);
    $stmt->bind_param("ii", $template_id, $user_id);
    
    echo "<p>Query: <code>$test_sql</code></p>";
    echo "<p>Parameters: template_id=$template_id, user_id=$user_id</p>";
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        $stmt->close();
        
        if ($template) {
            echo "<p class='success'>✓ Query SUCCESS - Template found!</p>";
            echo "<pre>" . print_r($template, true) . "</pre>";
        } else {
            echo "<p class='error'>✗ Query SUCCESS but NO template returned</p>";
            echo "<p>This means the WHERE conditions failed. Check:</p>";
            echo "<ul>";
            echo "<li>Template ID exists</li>";
            echo "<li>User ID matches</li>";
            echo "<li>is_active = 1</li>";
            echo "</ul>";
            
            // Check each condition separately
            echo "<h5>Debug each condition:</h5>";
            
            // Check ID only
            $sql = "SELECT id FROM irrigation_templates WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $template_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $id_check = $result->fetch_assoc();
            $stmt->close();
            echo "<p>ID exists: " . ($id_check ? 'YES' : 'NO') . "</p>";
            
            // Check user match
            $sql = "SELECT user_id FROM irrigation_templates WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $template_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            $template_user_id = $row['user_id'] ?? null;
            echo "<p>Template User ID: $template_user_id</p>";
            echo "<p>Your User ID: $user_id</p>";
            echo "<p>User matches: " . ($template_user_id == $user_id ? 'YES' : 'NO') . "</p>";
            
            // Check active status
            $sql = "SELECT is_active FROM irrigation_templates WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $template_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            $is_active = $row['is_active'] ?? null;
            echo "<p>Template active (is_active): " . ($is_active ? 'YES (1)' : 'NO (0)') . "</p>";
        }
    } else {
        echo "<p class='error'>✗ Query FAILED: " . $stmt->error . "</p>";
    }
} else {
    echo "<p class='warning'>⚠ No template ID to test with</p>";
}
echo "</div>";

// Test crop varieties table
echo "<div class='debug-section'>";
echo "<h3>10. Crop Varieties Test</h3>";
$sql = "SELECT COUNT(*) as count FROM template_crop_varieties";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
echo "<p>Total crop varieties in database: {$row['count']}</p>";

if ($template_id > 0) {
    $sql = "SELECT COUNT(*) as count FROM template_crop_varieties WHERE template_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    echo "<p>Crop varieties for template ID $template_id: {$row['count']}</p>";
}
echo "</div>";

// Summary
echo "<div class='debug-section alert alert-info'>";
echo "<h3>Summary</h3>";
echo "<ol>";
echo "<li>Check if you're logged in with the right user</li>";
echo "<li>Check if the template ID in URL is correct</li>";
echo "<li>Check if you own the template (user_id matches)</li>";
echo "<li>Check if template is active (is_active = 1)</li>";
echo "<li>Check if tables exist in database</li>";
echo "</ol>";

echo "<h4>Common Solutions:</h4>";
echo "<ul>";
echo "<li><strong>If you have no templates:</strong> Go to <a href='quotation.php'>quotation.php</a> and create one</li>";
echo "<li><strong>If template belongs to wrong user:</strong> Check your session/login</li>";
echo "<li><strong>If template is inactive:</strong> You might need to restore it from database</li>";
echo "<li><strong>If tables don't exist:</strong> Run your database setup script again</li>";
echo "</ul>";
echo "</div>";

// Quick actions
echo "<div class='text-center mt-4'>";
echo "<a href='quotation.php' class='btn btn-primary me-2'>Go to Templates Page</a>";
echo "<a href='test_template_debug.php' class='btn btn-secondary me-2'>Refresh Test</a>";
echo "<a href='logout.php' class='btn btn-outline-danger'>Logout & Test Login</a>";
echo "</div>";

echo "</div>"; // Close container
echo "</body>";
echo "</html>";

// Close connection
if (isset($conn)) {
    $conn->close();
}
?>