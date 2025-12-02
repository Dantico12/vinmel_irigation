<?php
// header.php - Should NOT contain any function declarations

// Check if config is already included, if not, include it
if (!class_exists('Database')) {
    require_once 'config.php';
}

// Get current user and period info using functions from config.php
$current_user = getCurrentUser();
$database = new Database();
$db = $database->getConnection();

// Use the function from config.php - NO DECLARATION HERE
$current_period = getCurrentTimePeriod($db);

// Create period info array for display
if ($current_period) {
    $period_info = [
        'exists' => true,
        'period_name' => $current_period['period_name'] ?? 'Unknown Period',
        'date_range' => date('M j, Y', strtotime($current_period['start_date'] ?? date('Y-m-d'))) . " - " . 
                       date('M j, Y', strtotime($current_period['end_date'] ?? date('Y-m-d'))),
        'is_locked' => (bool)($current_period['is_locked'] ?? false),
        'is_active' => (bool)($current_period['is_active'] ?? false)
    ];
} else {
    $period_info = [
        'exists' => false,
        'period_name' => "No Active Period",
        'date_range' => "N/A",
        'is_locked' => true,
        'is_active' => false
    ];
}
?>

<!-- Your existing HTML structure for header -->
<div class="top-header">
    <div class="header-content">
        <div class="header-left">
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a href="index.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-tint"></i>
                </div>
                <span class="logo-text">Vinmel Irrigation</span>
            </a>
            
            <?php if ($period_info['exists']): ?>
            <div class="period-status-container">
                <div class="period-indicator <?php echo $period_info['is_active'] ? 'period-active' : ($period_info['is_locked'] ? 'period-locked' : 'period-inactive'); ?>">
                    <div class="period-main">
                        <span class="period-name"><?php echo $period_info['period_name']; ?></span>
                        <span class="status-badge <?php echo $period_info['is_active'] ? 'active' : ($period_info['is_locked'] ? 'locked' : 'inactive'); ?>">
                            <?php echo $period_info['is_active'] ? 'Active' : ($period_info['is_locked'] ? 'Locked' : 'Inactive'); ?>
                        </span>
                    </div>
                    <div class="period-dates"><?php echo $period_info['date_range']; ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="header-right">
            <div class="user-menu">
                <button class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </button>
                
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        $initials = 'U';
                        if ($current_user && isset($current_user['name'])) {
                            $name_parts = explode(' ', $current_user['name']);
                            $initials = strtoupper(substr($name_parts[0], 0, 1));
                            if (count($name_parts) > 1) {
                                $initials .= strtoupper(substr($name_parts[1], 0, 1));
                            }
                        }
                        echo $initials;
                        ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo $current_user['name'] ?? 'User'; ?></div>
                        <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $current_user['role'] ?? 'user')); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>