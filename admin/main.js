// Main JavaScript for Dashboard Functionality

document.addEventListener('DOMContentLoaded', function() {
    // Mobile Menu Toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (mobileMenuToggle && sidebar) {
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 992 && sidebar && !sidebar.contains(event.target) && 
            mobileMenuToggle && !mobileMenuToggle.contains(event.target)) {
            sidebar.classList.remove('mobile-open');
        }
    });
    
    // Auto-refresh period status every 30 seconds
    setInterval(function() {
        const periodElement = document.querySelector('.period-indicator');
        if (periodElement) {
            // Check if we're on the hour to reload for period changes
            const currentTime = new Date();
            const minutes = currentTime.getMinutes();
            
            if (minutes === 0) {
                window.location.reload();
            }
        }
    }, 30000);
    
    // Add loading animation to cards
    const cards = document.querySelectorAll('.dashboard-card, .stat-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // Real-time clock update
    function updateClock() {
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        };
        
        const clockElements = document.querySelectorAll('.period-dates .fa-calendar');
        clockElements.forEach(element => {
            if (element.parentNode) {
                element.parentNode.innerHTML = 
                    `<i class="fas fa-calendar me-1"></i>${now.toLocaleDateString('en-US', options)}`;
            }
        });
    }
    
    setInterval(updateClock, 1000);
    updateClock();
    
    // Notification bell click handler
    const notificationBell = document.querySelector('.notification-bell');
    if (notificationBell) {
        notificationBell.addEventListener('click', function() {
            // TODO: Implement notification dropdown
            alert('Notifications feature coming soon!');
        });
    }
});