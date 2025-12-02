<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo text-center">
            <div class="logo-icon mx-auto">
                <i class="fas fa-tint fa-lg"></i>
            </div>
            <h4 class="mt-2 mb-1">Vinmel Irrigation</h4>
            <p class="text-light mb-0">Seller Portal</p>
        </div>
    </div>
    
    <div class="sidebar-nav">
        <a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        
        <a href="time_periods.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'time_periods.php' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i>
            <span>Time Periods</span>
        </a>
        
        <a href="products.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : '' ?>">
            <i class="fas fa-boxes"></i>
            <span>Incoming Products</span>
        </a>
        
        <a href="pos.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'pos.php' ? 'active' : '' ?>">
            <i class="fas fa-cash-register"></i>
            <span>POS System</span>
        </a>
        
        <a href="inventory.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : '' ?>">
            <i class="fas fa-warehouse"></i>
            <span>Inventory</span>
        </a>
        
        <div class="sidebar-divider"></div>
       
        
        <a href="logout.php" class="nav-link text-danger">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</nav>

<script>
    // Active link handling
    document.addEventListener('DOMContentLoaded', function() {
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (linkHref === currentPage) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    });
</script>