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

// Decode JSON data
$items = json_decode($template['items_json'], true) ?? [];
$design_summary = json_decode($template['design_summary'], true) ?? [];

// Calculate totals
$total_material = 0;
foreach ($items as $item) {
    $total_material += ($item['amount'] ?? 0);
}

$labor_percentage = floatval($template['labor_percentage'] ?? 35);
$discount_percentage = floatval($template['discount_percentage'] ?? 5);
$tax_rate = floatval($template['tax_rate'] ?? 16);

$labor_cost = $total_material * ($labor_percentage / 100);
$subtotal = $total_material + $labor_cost;
$discount_amount = $subtotal * ($discount_percentage / 100);
$taxable_amount = $subtotal - $discount_amount;
$tax_amount = $taxable_amount * ($tax_rate / 100);
$grand_total = $taxable_amount + $tax_amount;

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
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white !important;
                color: black !important;
            }
            
            .detail-card, .summary-box {
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
                                <?php if ($template['irrigation_type'] == 'drip'): ?>
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
                                <span class="template-badge"><?php echo strtoupper($template['irrigation_type']); ?> IRRIGATION</span>
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
                                <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($template['updated_at'])); ?></div>
                            </div>
                        </div>

                        <!-- Design Parameters -->
                        <div class="detail-card">
                            <h5 class="mb-4"><i class="fas fa-ruler-combined me-2"></i>Design Parameters</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Land Size</div>
                                    <div class="detail-value"><?php echo number_format($template['land_size'], 2); ?> <?php echo $template['land_unit']; ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Crop Type</div>
                                    <div class="detail-value"><?php echo ucfirst($template['crop_type']); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Crop Variety</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($template['crop_variety'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Irrigation Type</div>
                                    <div class="detail-value"><?php echo ucfirst($template['irrigation_type']); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Row Spacing</div>
                                    <div class="detail-value"><?php echo number_format($template['row_spacing'], 2); ?> m</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Plant Spacing</div>
                                    <div class="detail-value"><?php echo number_format($template['plant_spacing'], 2); ?> m</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">Water Pressure</div>
                                    <div class="detail-value"><?php echo number_format($template['water_pressure'], 1); ?> Bar</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="detail-label">System Efficiency</div>
                                    <div class="detail-value"><?php echo number_format($template['system_efficiency'], 1); ?>%</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Items and Summary -->
                    <div class="col-md-8">
                        <!-- Items Table -->
                        <div class="detail-card mb-4">
                            <h5 class="mb-4"><i class="fas fa-list me-2"></i>Template Items (<?php echo count($items); ?> items)</h5>
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
                                        <?php if (empty($items)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">
                                                    No items found in this template
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($items as $index => $item): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($item['units'] ?? ''); ?></td>
                                                <td class="text-end"><?php echo number_format($item['quantity'] ?? 0, 2); ?></td>
                                                <td class="text-end"><?php echo number_format($item['rate'] ?? 0, 2); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($item['amount'] ?? 0, 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="5" class="text-end fw-bold">Total Material Cost:</td>
                                            <td class="text-end fw-bold text-primary">KES <?php echo number_format($total_material, 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Cost Summary -->
                        <div class="summary-box">
                            <h5 class="mb-4"><i class="fas fa-calculator me-2"></i>Cost Summary</h5>
                            <div class="summary-item">
                                <span>Material Cost:</span>
                                <span class="fw-bold">KES <?php echo number_format($total_material, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Labor Cost (<?php echo $labor_percentage; ?>%):</span>
                                <span class="fw-bold">KES <?php echo number_format($labor_cost, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Subtotal:</span>
                                <span class="fw-bold">KES <?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Discount (<?php echo $discount_percentage; ?>%):</span>
                                <span class="fw-bold text-danger">- KES <?php echo number_format($discount_amount, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Taxable Amount:</span>
                                <span class="fw-bold">KES <?php echo number_format($taxable_amount, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Tax (<?php echo $tax_rate; ?>%):</span>
                                <span class="fw-bold">KES <?php echo number_format($tax_amount, 2); ?></span>
                            </div>
                            <div class="summary-item summary-total">
                                <span>GRAND TOTAL:</span>
                                <span>KES <?php echo number_format($grand_total, 2); ?></span>
                            </div>
                        </div>

                        <!-- Use Template Button -->
                        <div class="mt-4 text-center no-print">
                            <button class="btn btn-primary btn-lg" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#useTemplateModal"
                                    data-template-id="<?php echo $template['id']; ?>"
                                    data-template-name="<?php echo htmlspecialchars($template['template_name']); ?>">
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
                                   placeholder="Enter project name">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   placeholder="Optional">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" 
                                   placeholder="e.g., Nyandarua-Murungaru">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Land Size (<?php echo $template['land_unit']; ?>)</label>
                            <input type="number" class="form-control" name="land_size" 
                                   step="0.01" min="0.01" value="<?php echo $template['land_size']; ?>">
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="save_quotation" id="saveQuotation" checked>
                            <label class="form-check-label" for="saveQuotation">
                                Save quotation to database
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="generate_from_template" class="btn btn-primary">Generate Quotation</button>
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
                
                document.getElementById('useTemplateId').value = templateId;
                document.getElementById('useTemplateName').textContent = templateName;
                
                const now = new Date();
                const dateStr = now.toISOString().slice(0,10).replace(/-/g, '');
                const timeStr = now.toTimeString().slice(0,8).replace(/:/g, '');
                document.querySelector('#useTemplateForm input[name="quotation_number"]').value = 
                    `QTN-${dateStr}-${timeStr}`;
            });
        }
        
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.toggle('mobile-open');
            }
        });
        
        // Print function
        function printTemplate() {
            window.print();
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