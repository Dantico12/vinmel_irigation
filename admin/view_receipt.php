<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';


// Check authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get receipt ID from query parameter
$receipt_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($receipt_id <= 0) {
    die("Invalid receipt ID");
}

// Get receipt details from receipts table
$sql = "SELECT * FROM receipts WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $receipt_id);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();

if (!$receipt) {
    die("Receipt not found");
}

// Check if user has permission (admin can view all, seller can only view their own)
if ($_SESSION['role'] !== 'super_admin' && $receipt['seller_id'] != $_SESSION['user_id']) {
    die("Access denied. You can only view your own receipts.");
}

// Decode JSON data
$items = json_decode($receipt['items_json'], true);
$company_details = json_decode($receipt['company_details'], true);

// Determine view mode (print or view)
$mode = isset($_GET['mode']) && $_GET['mode'] === 'print' ? 'print' : 'view';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= htmlspecialchars($receipt['receipt_number']) ?> - Vinmel Irrigation</title>
    
    <?php if ($mode === 'print'): ?>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; }
        .receipt-container { width: 300px; margin: 0 auto; border: 1px solid #ddd; padding: 20px; }
        .receipt-header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
        .receipt-header h2 { margin: 5px 0; font-size: 16px; }
        .receipt-info { margin: 10px 0; }
        .info-row { display: flex; justify-content: space-between; margin: 3px 0; }
        .items-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .items-table th, .items-table td { border-bottom: 1px solid #ddd; padding: 5px; text-align: left; }
        .items-table th { border-bottom: 2px solid #000; font-size: 11px; }
        .total-section { margin-top: 10px; }
        .total-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .total-final { font-weight: bold; font-size: 13px; border-top: 2px solid #000; padding-top: 5px; }
        .receipt-footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 2px dashed #000; font-size: 10px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
            .receipt-container { border: none; width: 100%; }
        }
    </style>
    <?php else: ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .receipt-view { max-width: 400px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .receipt-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; text-align: center; }
        .receipt-body { padding: 20px; }
        .info-row { display: flex; justify-content: space-between; margin: 8px 0; padding: 5px; border-bottom: 1px dashed #eee; }
        .info-label { font-weight: bold; color: #666; }
        .info-value { text-align: right; }
        .items-table { width: 100%; margin: 20px 0; }
        .items-table th { background: #f8f9fa; font-weight: 600; }
        .total-section { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .total-row { display: flex; justify-content: space-between; margin: 5px 0; }
        .total-final { font-size: 18px; font-weight: bold; color: #28a745; border-top: 2px solid #dee2e6; padding-top: 10px; }
        .action-buttons { display: flex; gap: 10px; margin-top: 20px; }
    </style>
    <?php endif; ?>
</head>
<body>
    <?php if ($mode === 'view'): ?>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>
                <i class="fas fa-receipt me-2"></i>
                Receipt <?= htmlspecialchars($receipt['receipt_number']) ?>
            </h1>
            <div class="action-buttons">
                <a href="view_receipt.php?id=<?= $receipt_id ?>&mode=print" target="_blank" class="btn btn-primary">
                    <i class="fas fa-print me-2"></i>Print
                </a>
                <a href="income.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Sales
                </a>
            </div>
        </div>
        
        <div class="receipt-view">
            <div class="receipt-header">
                <h2><?= htmlspecialchars($company_details['name'] ?? 'Vinmel Irrigation') ?></h2>
                <p class="mb-1"><?= htmlspecialchars($company_details['address'] ?? 'Nairobi, Kenya') ?></p>
                <p class="mb-0">Tel: <?= htmlspecialchars($company_details['phone'] ?? '+254 700 000000') ?></p>
            </div>
            
            <div class="receipt-body">
                <!-- Receipt Info -->
                <div class="receipt-info">
                    <div class="info-row">
                        <span class="info-label">Receipt #:</span>
                        <span class="info-value"><?= htmlspecialchars($receipt['receipt_number']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Date & Time:</span>
                        <span class="info-value"><?= date('Y-m-d H:i:s', strtotime($receipt['transaction_date'])) ?></span>
                    </div>
                    <?php if ($receipt['customer_name']): ?>
                    <div class="info-row">
                        <span class="info-label">Customer:</span>
                        <span class="info-value"><?= htmlspecialchars($receipt['customer_name']) ?></span>
                    </div>
                    <?php if ($receipt['customer_phone']): ?>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?= htmlspecialchars($receipt['customer_phone']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">Seller:</span>
                        <span class="info-value"><?= htmlspecialchars($receipt['seller_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Method:</span>
                        <span class="info-value badge bg-success"><?= strtoupper(htmlspecialchars($receipt['payment_method'])) ?></span>
                    </div>
                </div>
                
                <!-- Items Table -->
                <table class="table items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <div><?= htmlspecialchars($item['product_name']) ?></div>
                                <small class="text-muted">SKU: <?= htmlspecialchars($item['sku']) ?></small>
                            </td>
                            <td class="text-center"><?= $item['quantity'] ?></td>
                            <td class="text-end">KSh <?= number_format($item['unit_price'], 2) ?></td>
                            <td class="text-end">KSh <?= number_format($item['total_price'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Totals -->
                <div class="total-section">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span>KSh <?= number_format($receipt['total_amount'], 2) ?></span>
                    </div>
                    <?php if ($receipt['discount_amount'] > 0): ?>
                    <div class="total-row">
                        <span>Discount:</span>
                        <span class="text-danger">- KSh <?= number_format($receipt['discount_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($receipt['tax_amount'] > 0): ?>
                    <div class="total-row">
                        <span>Tax (16%):</span>
                        <span>KSh <?= number_format($receipt['tax_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="total-row total-final">
                        <span>GRAND TOTAL:</span>
                        <span>KSh <?= number_format($receipt['net_amount'], 2) ?></span>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="text-center text-muted mt-4">
                    <p class="mb-1">Thank you for your business!</p>
                    <p class="mb-0"><?= htmlspecialchars($company_details['name'] ?? 'Vinmel Irrigation') ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Print View -->
    <div class="receipt-container">
        <div class="no-print" style="text-align: center; margin-bottom: 20px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
                <i class="fas fa-print"></i> Print Receipt
            </button>
        </div>
        
        <div class="receipt-header">
            <h2><?= htmlspecialchars($company_details['name'] ?? 'Vinmel Irrigation') ?></h2>
            <p><?= htmlspecialchars($company_details['address'] ?? 'Nairobi, Kenya') ?></p>
            <p>Tel: <?= htmlspecialchars($company_details['phone'] ?? '+254 700 000000') ?></p>
            <p>Email: <?= htmlspecialchars($company_details['email'] ?? 'info@vinmel.com') ?></p>
        </div>
        
        <div class="receipt-info">
            <div class="info-row">
                <span>Receipt #:</span>
                <span><?= htmlspecialchars($receipt['receipt_number']) ?></span>
            </div>
            <div class="info-row">
                <span>Date:</span>
                <span><?= date('Y-m-d H:i:s', strtotime($receipt['transaction_date'])) ?></span>
            </div>
            <?php if ($receipt['customer_name']): ?>
            <div class="info-row">
                <span>Customer:</span>
                <span><?= htmlspecialchars($receipt['customer_name']) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span>Seller:</span>
                <span><?= htmlspecialchars($receipt['seller_name']) ?></span>
            </div>
            <div class="info-row">
                <span>Payment:</span>
                <span><?= strtoupper(htmlspecialchars($receipt['payment_method'])) ?></span>
            </div>
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['product_name']) ?><br><small><?= htmlspecialchars($item['sku']) ?></small></td>
                    <td class="text-center"><?= $item['quantity'] ?></td>
                    <td class="text-end">KSh <?= number_format($item['unit_price'], 2) ?></td>
                    <td class="text-end">KSh <?= number_format($item['total_price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="total-section">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>KSh <?= number_format($receipt['total_amount'], 2) ?></span>
            </div>
            <?php if ($receipt['discount_amount'] > 0): ?>
            <div class="total-row">
                <span>Discount:</span>
                <span>- KSh <?= number_format($receipt['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($receipt['tax_amount'] > 0): ?>
            <div class="total-row">
                <span>Tax (16%):</span>
                <span>KSh <?= number_format($receipt['tax_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row total-final">
                <span>TOTAL:</span>
                <span>KSh <?= number_format($receipt['net_amount'], 2) ?></span>
            </div>
        </div>
        
        <div class="receipt-footer">
            <p>Thank you for your business!</p>
            <p><?= htmlspecialchars($company_details['name'] ?? 'Vinmel Irrigation') ?></p>
            <p>Printed: <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
    
    <script>
        // Auto-print when page loads in print mode
        window.onload = function() {
            window.print();
            // Return to view mode after printing
            setTimeout(function() {
                window.location.href = 'view_receipt.php?id=<?= $receipt_id ?>';
            }, 1000);
        };
    </script>
    <?php endif; ?>
    
    <?php if ($mode === 'view'): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>
</body>
</html>