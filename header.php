<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include necessary files
require_once 'config.php';
require_once 'functions.php'; // Include central functions

// Get database connection and period info
$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'] ?? null;

// Get current period information
$current_period = getCurrentTimePeriod($user_id, $db);

// Create period info array for display
if ($current_period) {
    $period_info = [
        'exists' => true,
        'period_name' => $current_period['period_name'],
        'date_range' => date('M j, Y', strtotime($current_period['start_date'])) . " - " . 
                       date('M j, Y', strtotime($current_period['end_date'])),
        'is_locked' => (bool)$current_period['is_locked'],
        'is_active' => (bool)$current_period['is_active']
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

<header class="top-header">
    <div class="header-content">
        <div class="header-left">
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a href="dashboard.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-tint"></i>
                </div>
                <span class="logo-text">Vinmel Irrigation</span>
            </a>
            
            <!-- Period Status Display -->
            <div class="period-status-container">
                <?php if ($period_info['exists']): ?>
                    <div class="period-indicator <?= $period_info['is_locked'] ? 'period-locked' : 'period-active' ?>">
                        <div class="period-main">
                            <i class="fas <?= $period_info['is_locked'] ? 'fa-lock' : 'fa-calendar-check' ?> me-1"></i>
                            <span class="period-name"><?= $period_info['period_name'] ?></span>
                            <?php if ($period_info['is_locked']): ?>
                                <span class="status-badge locked">
                                    <i class="fas fa-lock me-1"></i>Locked
                                </span>
                            <?php else: ?>
                                <span class="status-badge active">
                                    <i class="fas fa-unlock me-1"></i>Active
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="period-dates">
                            <small class="text-light opacity-75">
                                <i class="fas fa-calendar me-1"></i>
                                <?= $period_info['date_range'] ?>
                            </small>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="period-indicator period-inactive">
                        <div class="period-main">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <span class="period-name">No Active Period</span>
                            <span class="status-badge inactive">
                                <i class="fas fa-ban me-1"></i>Inactive
                            </span>
                        </div>
                        <div class="period-dates">
                            <small class="text-light opacity-75">
                                <a href="time_periods.php" class="text-warning text-decoration-none">
                                    <i class="fas fa-plus-circle me-1"></i>Create Period
                                </a>
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="header-right">
            <div class="user-menu">
                <button class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </button>
                
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?= isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'User' ?></div>
                        <div class="user-role"><?= isset($_SESSION['role']) ? ucfirst(str_replace('_', ' ', $_SESSION['role'])) : 'Seller' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>


<script>
document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
    }
});

document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.sidebar');
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const isClickInsideSidebar = sidebar?.contains(event.target);
    const isClickOnToggle = mobileToggle?.contains(event.target);
    
    if (window.innerWidth <= 768 && sidebar && !isClickInsideSidebar && !isClickOnToggle) {
        sidebar.classList.remove('mobile-open');
    }
});

// Auto-refresh period status every 30 seconds
setInterval(function() {
    // You can implement AJAX refresh here if needed
    // For now, we'll just reload the page if period might have changed
    const periodElement = document.querySelector('.period-indicator');
    if (periodElement) {
        // Check if we're on a page where period status matters
        const currentTime = new Date();
        const minutes = currentTime.getMinutes();
        
        // Reload on the hour to catch period changes
        if (minutes === 0) {
            window.location.reload();
        }
    }
}, 30000);
</script>