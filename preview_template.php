<?php
// view_template.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$conn = (new Database())->getConnection();

if (isset($_GET['id'])) {
    $template_id = $_GET['id'];
    $user_id = $_SESSION['user_id'];
    
    // Get template data
    $stmt = $conn->prepare("SELECT * FROM irrigation_templates WHERE id = ? AND user_id = ? AND is_active = 1");
    $stmt->bind_param("ii", $template_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = $result->fetch_assoc();
    $stmt->close();
    
    if (!$template) {
        die("Template not found or you don't have permission to view it.");
    }
    
    $items = json_decode($template['items_json'], true) ?? [];
    $design_summary = json_decode($template['design_summary'], true) ?? [];
    
    // Calculate totals
    $total_material = 0;
    foreach ($items as $item) {
        $total_material += $item['amount'];
    }
    $labor_cost = $total_material * ($template['labor_percentage'] / 100);
    $subtotal = $total_material + $labor_cost;
    $discount_amount = $subtotal * ($template['discount_percentage'] / 100);
    $taxable_amount = $subtotal - $discount_amount;
    $tax_amount = $taxable_amount * ($template['tax_rate'] / 100);
    $grand_total = $taxable_amount + $tax_amount;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template: <?php echo htmlspecialchars($template['template_name'] ?? 'View Template'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            padding: 20px;
        }
        .template-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .template-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .template-content {
            padding: 40px;
        }
        .badge-type {
            font-size: 0.8rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }
        .table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .total-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .total-row:last-child {
            border-bottom: none;
        }
        .grand-total {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="template-container">
        <div class="template-header">
            <h1><?php echo htmlspecialchars($template['template_name']); ?></h1>
            <p class="lead mb-0"><?php echo htmlspecialchars($template['description']); ?></p>
            <div class="mt-3">
                <span class="badge badge-type bg-primary"><?php echo strtoupper($template['irrigation_type']); ?></span>
                <span class="badge badge-type bg-secondary"><?php echo strtoupper($template['template_type']); ?></span>
            </div>
        </div>
        
        <div class="template-content">
            <!-- Basic Info -->
            <div class="row mb-5">
                <div class="col-md-6">
                    <h4><i class="fas fa-info-circle text-primary"></i> Basic Information</h4>
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Project:</strong></td>
                            <td><?php echo htmlspecialchars($template['project_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Customer:</strong></td>
                            <td><?php echo htmlspecialchars($template['customer_name'] ?: 'Not specified'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Location:</strong></td>
                            <td><?php echo htmlspecialchars($template['location'] ?: 'Not specified'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Created:</strong></td>
                            <td><?php echo date('F d, Y', strtotime($template['created_at'])); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h4><i class="fas fa-cogs text-primary"></i> Design Parameters</h4>
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Land Area:</strong></td>
                            <td><?php echo $template['land_size']; ?> <?php echo $template['land_unit']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Crop Type:</strong></td>
                            <td><?php echo ucfirst($template['crop_type']); ?> (<?php echo $template['crop_variety'] ?: 'Any'; ?>)</td>
                        </tr>
                        <tr>
                            <td><strong>Row Spacing:</strong></td>
                            <td><?php echo $template['row_spacing']; ?> m</td>
                        </tr>
                        <tr>
                            <td><strong>Plant Spacing:</strong></td>
                            <td><?php echo $template['plant_spacing']; ?> m</td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Items List -->
            <h4><i class="fas fa-list text-primary"></i> Items List</h4>
            <div class="table-responsive mb-5">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th>Units</th>
                            <th>Quantity</th>
                            <th>Rate (KES)</th>
                            <th>Amount (KES)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo htmlspecialchars($item['units']); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td><?php echo number_format($item['rate'], 2); ?></td>
                            <td><?php echo number_format($item['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Cost Summary -->
            <div class="total-section">
                <h4><i class="fas fa-calculator text-primary"></i> Cost Summary</h4>
                <div class="row">
                    <div class="col-md-8 offset-md-2">
                        <div class="total-row">
                            <span>Total Material Cost:</span>
                            <span>KES <?php echo number_format($total_material, 2); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Labor Cost (<?php echo $template['labor_percentage']; ?>%):</span>
                            <span>KES <?php echo number_format($labor_cost, 2); ?></span>
                        </div>
                        <div class="total-row">
                            <span>Subtotal:</span>
                            <span>KES <?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <?php if ($discount_amount > 0): ?>
                        <div class="total-row">
                            <span>Discount (<?php echo $template['discount_percentage']; ?>%):</span>
                            <span>- KES <?php echo number_format($discount_amount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($tax_amount > 0): ?>
                        <div class="total-row">
                            <span>Tax (<?php echo $template['tax_rate']; ?>%):</span>
                            <span>KES <?php echo number_format($tax_amount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="total-row grand-total">
                            <span>GRAND TOTAL:</span>
                            <span>KES <?php echo number_format($grand_total, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="text-center mt-5">
                <a href="irrigation_quotations.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Templates
                </a>
                <button onclick="window.print()" class="btn btn-primary ms-2">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="irrigation_quotations.php?delete_template=<?php echo $template_id; ?>" 
                   class="btn btn-danger ms-2"
                   onclick="return confirm('Are you sure you want to delete this template?')">
                    <i class="fas fa-trash"></i> Delete
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>