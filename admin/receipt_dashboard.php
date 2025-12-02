<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();

// Check authentication - only admin/super_admin can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'admin')) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Handle filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search = $_GET['search'] ?? '';
$seller_id = $_GET['seller_id'] ?? '';
$period_id = $_GET['period_id'] ?? '';

// Get all sellers for filter
$sellers_sql = "SELECT id, name FROM users WHERE role IN ('admin', 'super_admin') ORDER BY name";
$sellers_result = $db->query($sellers_sql);
$sellers = [];
while ($seller = $sellers_result->fetch_assoc()) {
    $sellers[] = $seller;
}

// Get all periods for filter
$periods_sql = "SELECT * FROM time_periods ORDER BY year DESC, month DESC";
$periods_result = $db->query($periods_sql);
$periods = [];
while ($period = $periods_result->fetch_assoc()) {
    $periods[] = $period;
}

// Build query
$query = "SELECT r.*, tp.period_name 
          FROM receipts r
          LEFT JOIN time_periods tp ON r.period_id = tp.id
          WHERE 1=1";

$params = [];
$types = '';

// Apply filters
if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND DATE(r.transaction_date) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

if (!empty($search)) {
    $query .= " AND (r.receipt_number LIKE ? OR r.customer_name LIKE ? OR r.seller_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if (!empty($seller_id)) {
    $query .= " AND r.seller_id = ?";
    $params[] = $seller_id;
    $types .= 'i';
}

if (!empty($period_id)) {
    $query .= " AND r.period_id = ?";
    $params[] = $period_id;
    $types .= 'i';
}

$query .= " ORDER BY r.transaction_date DESC";

$stmt = $db->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$receipts_result = $stmt->get_result();

// Calculate totals
$totals_query = "SELECT 
    COUNT(*) as total_receipts,
    SUM(net_amount) as total_amount,
    SUM(tax_amount) as total_tax,
    SUM(discount_amount) as total_discount
    FROM receipts r WHERE 1=1";

// Apply same filters to totals
$totals_params = [];
$totals_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $totals_query .= " AND DATE(r.transaction_date) BETWEEN ? AND ?";
    $totals_params[] = $start_date;
    $totals_params[] = $end_date;
    $totals_types .= 'ss';
}

if (!empty($search)) {
    $totals_query .= " AND (r.receipt_number LIKE ? OR r.customer_name LIKE ? OR r.seller_name LIKE ?)";
    $search_param = "%$search%";
    $totals_params[] = $search_param;
    $totals_params[] = $search_param;
    $totals_params[] = $search_param;
    $totals_types .= 'sss';
}

if (!empty($seller_id)) {
    $totals_query .= " AND r.seller_id = ?";
    $totals_params[] = $seller_id;
    $totals_types .= 'i';
}

if (!empty($period_id)) {
    $totals_query .= " AND r.period_id = ?";
    $totals_params[] = $period_id;
    $totals_types .= 'i';
}

$totals_stmt = $db->prepare($totals_query);
if (!empty($totals_params)) {
    $totals_stmt->bind_param($totals_types, ...$totals_params);
}
$totals_stmt->execute();
$totals = $totals_stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts Archive - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stats-card { border-left: 4px solid #007bff; }
        .stats-value { font-size: 1.5rem; font-weight: bold; }
        .receipt-badge { font-size: 0.7rem; }
        .payment-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        .badge-cash { background: #d4edda; color: #155724; }
        .badge-card { background: #cce7ff; color: #004085; }
        .badge-mobile { background: #e2e3ff; color: #383d41; }
        .badge-bank { background: #f8d7da; color: #721c24; }
        .table-hover tbody tr:hover { background-color: rgba(0,0,0,0.025); }
    </style>
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>
        
        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header mb-4">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-archive me-2"></i>
                            Receipts Archive
                        </h1>
                        <p class="text-muted mb-0">View and manage all sales receipts</p>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Total Receipts</h5>
                                <div class="stats-value text-primary">
                                    <?= number_format($totals['total_receipts'] ?? 0) ?>
                                </div>
                                <p class="card-text mb-0">All stored receipts</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Total Sales</h5>
                                <div class="stats-value text-success">
                                    KSh <?= number_format($totals['total_amount'] ?? 0, 2) ?>
                                </div>
                                <p class="card-text mb-0">Gross sales amount</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Total Tax</h5>
                                <div class="stats-value text-warning">
                                    KSh <?= number_format($totals['total_tax'] ?? 0, 2) ?>
                                </div>
                                <p class="card-text mb-0">Tax collected</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Total Discount</h5>
                                <div class="stats-value text-info">
                                    KSh <?= number_format($totals['total_discount'] ?? 0, 2) ?>
                                </div>
                                <p class="card-text mb-0">Discounts given</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Filter Receipts</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" name="start_date" class="form-control" 
                                           value="<?= htmlspecialchars($start_date) ?>">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">End Date</label>
                                    <input type="date" name="end_date" class="form-control" 
                                           value="<?= htmlspecialchars($end_date) ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Seller</label>
                                    <select name="seller_id" class="form-select">
                                        <option value="">All Sellers</option>
                                        <?php foreach ($sellers as $seller): ?>
                                            <option value="<?= $seller['id'] ?>" 
                                                <?= $seller_id == $seller['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($seller['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Period</label>
                                    <select name="period_id" class="form-select">
                                        <option value="">All Periods</option>
                                        <?php foreach ($periods as $period): ?>
                                            <option value="<?= $period['id'] ?>" 
                                                <?= $period_id == $period['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($period['period_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Receipt #, Customer..." 
                                               value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-12 mt-3">
                                    <div class="d-flex gap-2 justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-2"></i> Apply Filters
                                        </button>
                                        <a href="receipts_dashboard.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i> Clear
                                        </a>
                                        <button type="button" onclick="exportReceiptsCSV()" class="btn btn-success">
                                            <i class="fas fa-download me-2"></i> Export CSV
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Receipts Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-receipt me-2"></i>
                            All Receipts
                            <?php if (!empty($start_date) || !empty($search) || !empty($seller_id) || !empty($period_id)): ?>
                                <small class="text-muted">(Filtered Results)</small>
                            <?php endif; ?>
                        </h5>
                        <div class="text-muted">
                            Showing: <strong><?= $receipts_result->num_rows ?></strong> receipts
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover" id="receipts-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt #</th>
                                        <th>Customer</th>
                                        <th>Seller</th>
                                        <th>Items</th>
                                        <th>Total</th>
                                        <th>Tax</th>
                                        <th>Discount</th>
                                        <th>Net</th>
                                        <th>Payment</th>
                                        <th>Period</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($receipts_result->num_rows > 0): ?>
                                        <?php while ($receipt = $receipts_result->fetch_assoc()): 
                                            $items = json_decode($receipt['items_json'], true);
                                            $item_count = is_array($items) ? count($items) : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <?= date('j M Y', strtotime($receipt['transaction_date'])) ?><br>
                                                    <small class="text-muted"><?= date('H:i', strtotime($receipt['transaction_date'])) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark receipt-badge">
                                                        <?= htmlspecialchars($receipt['receipt_number']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= $receipt['customer_name'] ? 
                                                        htmlspecialchars($receipt['customer_name']) : 
                                                        '<span class="text-muted">Walk-in</span>' ?>
                                                    <?php if ($receipt['customer_phone']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($receipt['customer_phone']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($receipt['seller_name']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?= $item_count ?> items</span>
                                                </td>
                                                <td>
                                                    <strong>KSh <?= number_format($receipt['total_amount'], 2) ?></strong>
                                                </td>
                                                <td>
                                                    KSh <?= number_format($receipt['tax_amount'], 2) ?>
                                                </td>
                                                <td>
                                                    <?php if ($receipt['discount_amount'] > 0): ?>
                                                        <span class="text-danger">- KSh <?= number_format($receipt['discount_amount'], 2) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong class="text-success">KSh <?= number_format($receipt['net_amount'], 2) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="payment-badge badge-<?= $receipt['payment_method'] ?>">
                                                        <?= strtoupper(htmlspecialchars($receipt['payment_method'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($receipt['period_name'] ?? 'No Period') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="view_receipt.php?id=<?= $receipt['transaction_id'] ?>" 
                                                           class="btn btn-outline-primary" target="_blank"
                                                           title="View Receipt">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="view_receipt.php?id=<?= $receipt['transaction_id'] ?>&mode=print" 
                                                           class="btn btn-outline-secondary" target="_blank"
                                                           title="Print Receipt">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center py-5">
                                                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">No receipts found.</p>
                                                <?php if (!empty($start_date) || !empty($search) || !empty($seller_id) || !empty($period_id)): ?>
                                                    <a href="receipts_dashboard.php" class="btn btn-outline-secondary">
                                                        Clear Filters
                                                    </a>
                                                <?php else: ?>
                                                    <p class="text-muted">No receipts have been created yet.</p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if ($receipts_result->num_rows > 0): ?>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="5" class="text-end">TOTALS:</th>
                                        <th class="text-success">KSh <?= number_format($totals['total_amount'] ?? 0, 2) ?></th>
                                        <th class="text-warning">KSh <?= number_format($totals['total_tax'] ?? 0, 2) ?></th>
                                        <th class="text-danger">KSh <?= number_format($totals['total_discount'] ?? 0, 2) ?></th>
                                        <th class="text-success">KSh <?= number_format($totals['total_amount'] - $totals['total_discount'] + $totals['total_tax'] ?? 0, 2) ?></th>
                                        <th colspan="3"></th>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Export receipts to CSV
        function exportReceiptsCSV() {
            const table = document.getElementById('receipts-table');
            let csv = [];
            
            // Headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                if (th.textContent.trim() !== 'Actions') {
                    headers.push(th.textContent.trim());
                }
            });
            csv.push(headers.join(','));
            
            // Rows
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach((td, index) => {
                    if (index < headers.length) {
                        let text = td.textContent.trim().replace(/,/g, '');
                        // Remove KSh prefix from amounts
                        text = text.replace('KSh ', '');
                        rowData.push(text);
                    }
                });
                if (rowData.length > 0) {
                    csv.push(rowData.join(','));
                }
            });
            
            // Download
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'receipts_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
        }

        // Auto-submit form when dates change
        document.querySelector('input[name="start_date"]')?.addEventListener('change', function() {
            if (this.value && document.querySelector('input[name="end_date"]').value) {
                this.form.submit();
            }
        });
        
        document.querySelector('input[name="end_date"]')?.addEventListener('change', function() {
            if (this.value && document.querySelector('input[name="start_date"]').value) {
                this.form.submit();
            }
        });

        // Auto-submit when period changes
        document.querySelector('select[name="period_id"]')?.addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });
    </script>
</body>
</html>