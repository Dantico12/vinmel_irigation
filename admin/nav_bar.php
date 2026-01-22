<nav class="sidebar" id="sidebar">
    <div class="sidebar-nav">

        <!-- Dashboard -->
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="admin_dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i>
                Dashboard Overview
            </a>
        </div>

        <!-- Managerial Section -->
        <div class="nav-section">
            <div class="nav-section-title">Managerial</div>
            <a href="periods.php" class="nav-link">
                <i class="fas fa-calendar-plus"></i>
                Start Period
            </a>
            <a href="add_product.php" class="nav-link">
                <i class="fas fa-plus-square"></i>
                Add Products
            </a>
            <a href="products_stats.php" class="nav-link">
                <i class="fas fa-chart-pie"></i>
                Products Stats
            </a>
        </div>

        <!-- Financial Management -->
        <div class="nav-section">
            <div class="nav-section-title">Financial Management</div>
            <a href="income.php" class="nav-link">
                <i class="fas fa-money-bill-wave"></i>
                Income & Sales
            </a>
            <a href="expenses.php" class="nav-link">
                <i class="fas fa-receipt"></i>
                Expenses
            </a>
            <a href="sales-tax.php" class="nav-link">
                <i class="fas fa-percentage"></i>
                Sales Tax
            </a>
        </div>

        <!-- Analytics & Reports -->
        <div class="nav-section">
            <div class="nav-section-title">Analytics & Reports</div>
            <a href="monthly.php" class="nav-link">
                <i class="fas fa-chart-bar"></i>
                Monthly Dashboard
            </a>
            <a href="annual.php" class="nav-link">
                <i class="fas fa-chart-line"></i>
                Annual Dashboard
            </a>
            <a href="five-year.php" class="nav-link">
                <i class="fas fa-chart-area"></i>
                5-Year Overview
            </a>
        </div>

        <!-- Business Setup -->
        <div class="nav-section">
            <div class="nav-section-title">Business Setup</div>
            <a href="products.php" class="nav-link">
                <i class="fas fa-boxes"></i>
                Products & Inventory
            </a>
            <a href="categories.php" class="nav-link">
                <i class="fas fa-balance-scale"></i>
                Balance Sheet
            </a>
        </div>

        <!-- System -->
        <div class="nav-section">
            <div class="nav-section-title">System</div>
            <a href="users.php" class="nav-link">
                <i class="fas fa-users"></i>
                User Management
            </a>
            <a href="logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>

    </div>
</nav>

<!-- Mobile Menu Toggle Button (Add to header.php if not already there) -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<script>
    // Mobile menu toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 992) {
                    if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        sidebar.classList.remove('mobile-open');
                    }
                }
            });
        }
        
        // Highlight active menu item
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage || 
                (currentPage === '' && href === 'admin_dashboard.php') ||
                (currentPage.includes('income') && href.includes('income'))) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    });
</script>