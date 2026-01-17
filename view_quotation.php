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

// Check if quotation ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Quotation ID is required.";
    header('Location: quotation.php');
    exit();
}

$quotation_id = intval($_GET['id']);

// Get quotation details
$stmt = $conn->prepare("
    SELECT q.*, t.template_name, p.period_name 
    FROM irrigation_quotations q
    LEFT JOIN irrigation_templates t ON q.template_id = t.id
    LEFT JOIN time_periods p ON q.period_id = p.id
    WHERE q.id = ? AND q.user_id = ?
");
$stmt->bind_param("ii", $quotation_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$quotation = $result->fetch_assoc();
$stmt->close();

if (!$quotation) {
    $_SESSION['error_message'] = "Quotation not found or you don't have permission to view it.";
    header('Location: quotation.php');
    exit();
}

// Decode items JSON
$items = json_decode($quotation['items_json'], true) ?? [];

include 'nav_bar.php';
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
    
    <style>
        .quotation-header {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--space-xl);
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-md);
        }
        
        .company-logo {
            font-size: 2.5rem;
            color: var(--primary-blue);
            font-weight: 700;
            margin-bottom: var(--space-sm);
        }
        
        .quotation-title {
            color: var(--primary-blue);
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 0;
        }
        
        .quotation-badge {
            background: var(--primary-blue);
            color: white;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .client-info {
            background: var(--light-bg);
            border-radius: var(--radius-md);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
        }
        
        .client-label {
            color: var(--medium-text);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .client-value {
            color: var(--dark-text);
            font-weight: 600;
            font-size: 1rem;
        }
        
        .summary-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: var(--space-lg);
            margin-bottom: var(--space-lg);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-row:last-child {
            border-bottom: none;
        }
        
        .total-row {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-blue);
        }
        
        .signature-area {
            margin-top: var(--space-xl);
            padding-top: var(--space-lg);
            border-top: 2px dashed var(--border-color);
        }
        
        .signature-box {
            text-align: center;
            padding: var(--space-md);
            border-top: 1px solid var(--dark-text);
            width: 200px;
            margin: 0 auto;
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
                font-size: 12pt;
            }
            
            .quotation-header {
                border: 1px solid #ddd;
                box-shadow: none;
            }
            
            .summary-card {
                border: 1px solid #ddd;
            }
            
            .page-break {
                page-break-before: always;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <header class="top-header no-print">
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

                <!-- Quotation Header -->
                <div class="quotation-header">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="company-logo">VINMEL IRRIGATION</div>
                            <p class="mb-0">
                                P.O. Box 1234, Nairobi<br>
                                Phone: +254 700 000 000<br>
                                Email: info@vinmelirrigation.com<br>
                                Website: www.vinmelirrigation.com
                            </p>
                        </div>
                        <div class="col-md-6 text-end">
                            <h1 class="quotation-title">QUOTATION</h1>
                            <div class="mb-3">
                                <span class="quotation-badge"><?php echo htmlspecialchars($quotation['quotation_number']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong>Date:</strong> <?php echo date('F d, Y', strtotime($quotation['created_at'])); ?>
                            </div>
                            <?php if ($quotation['period_name']): ?>
                            <div class="mb-2">
                                <strong>Period:</strong> <?php echo htmlspecialchars($quotation['period_name']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($quotation['template_name']): ?>
                            <div>
                                <strong>Template:</strong> <?php echo htmlspecialchars($quotation['template_name']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Client Information -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="client-info">
                                <h5 class="mb-3">CLIENT INFORMATION</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="client-label">Customer Name</div>
                                        <div class="client-value"><?php echo htmlspecialchars($quotation['customer_name'] ?: 'N/A'); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="client-label">Project Name</div>
                                        <div class="client-value"><?php echo htmlspecialchars($quotation['project_name']); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="client-label">Location</div>
                                        <div class="client-value"><?php echo htmlspecialchars($quotation['location'] ?: 'N/A'); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="client-label">Prepared By</div>
                                        <div class="client-value"><?php echo htmlspecialchars($user['name']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="client-info">
                                <h5 class="mb-3">PROJECT DETAILS</h5>
                                <div class="mb-3">
                                    <div class="client-label">Land Size</div>
                                    <div class="client-value"><?php echo number_format($quotation['land_size'], 2); ?> <?php echo $quotation['land_unit']; ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="client-label">Crop Type</div>
                                    <div class="client-value"><?php echo ucfirst($quotation['crop_type']); ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="client-label">Irrigation Type</div>
                                    <div class="client-value"><?php echo ucfirst($quotation['irrigation_type']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <div class="summary-card">
                    <h5 class="mb-4">QUOTATION ITEMS</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
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
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            No items found in this quotation
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
                        </table>
                    </div>
                </div>

                <!-- Cost Summary -->
                <div class="row">
                    <div class="col-md-8">
                        <!-- Terms and Conditions -->
                        <div class="summary-card">
                            <h5 class="mb-3">TERMS & CONDITIONS</h5>
                            <ol class="mb-0">
                                <li>This quotation is valid for 30 days from the date of issue.</li>
                                <li>Prices are subject to change without prior notice.</li>
                                <li>Payment terms: 50% advance, 50% upon completion.</li>
                                <li>Delivery: 2-3 weeks after order confirmation.</li>
                                <li>Warranty: 1 year on materials and workmanship.</li>
                                <li>Installation included in the quoted price.</li>
                                <li>Training on system operation provided.</li>
                            </ol>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <h5 class="mb-3">COST SUMMARY</h5>
                            <div class="summary-row">
                                <span>Material Cost:</span>
                                <span class="fw-bold">KES <?php echo number_format($quotation['total_material'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Labor Cost:</span>
                                <span class="fw-bold">KES <?php echo number_format($quotation['labor_cost'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Subtotal:</span>
                                <span class="fw-bold">KES <?php echo number_format($quotation['total_material'] + $quotation['labor_cost'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Discount:</span>
                                <span class="fw-bold text-danger">- KES <?php echo number_format($quotation['discount_amount'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Tax (16%):</span>
                                <span class="fw-bold">KES <?php echo number_format($quotation['tax_amount'], 2); ?></span>
                            </div>
                            <div class="summary-row total-row">
                                <span>GRAND TOTAL:</span>
                                <span>KES <?php echo number_format($quotation['grand_total'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Signatures -->
                <div class="signature-area">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>FOR CLIENT</h6>
                            <div class="signature-box">
                                <br><br>
                                <strong>Signature</strong><br>
                                <small>Date: _________________</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>FOR VINMEL IRRIGATION</h6>
                            <div class="signature-box">
                                <br><br>
                                <strong>Authorized Signature</strong><br>
                                <small>Date: <?php echo date('d/m/Y'); ?></small>
                            </div>
                        </div>
                    </div>
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
                        <a href="quotation.php" class="btn btn-outline-primary">
                            <i class="fas fa-plus me-2"></i> New Quotation
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.toggle('mobile-open');
            }
        });
        
        // PDF download function (placeholder)
        function downloadPDF() {
            alert('PDF download functionality would be implemented here. For now, please use the Print function and save as PDF.');
            // In a real implementation, you would use a PDF library like jsPDF or make an API call
        }
        
        // Print function
        function printQuotation() {
            window.print();
        }
        
        // Auto-print option (optional)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === 'true') {
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