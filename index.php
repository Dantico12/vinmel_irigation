<?php
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {

    // Redirect based on role
    if ($_SESSION['role'] === 'admin') {
        header("Location: dashboard.php"); // Seller dashboard
        exit();
    } elseif ($_SESSION['role'] === 'super_admin') {
        header("Location: ../admin/admin_dashboard.php"); // Admin dashboard
        exit();
    } else {
        // Optional: unknown role, logout
        session_destroy();
        header("Location: login.php");
        exit();
    }

} else {
    // User not logged in, redirect to login
    header("Location: login.php");
    exit();
}
?>
