<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

require_once 'config.php';

// GET DB CONNECTION
$db = (new Database())->getConnection();

/* ----------------------------
    AUTH + UTILITY FUNCTIONS
-----------------------------*/

function isLoggedIn() {
    return isset($_SESSION['user_id']) &&
           isset($_SESSION['user_ip']) &&
           $_SESSION['user_ip'] === $_SERVER['REMOTE_ADDR'];
}


function isSeller() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'; // FIXED TYPO
}

function redirectBasedOnRole() {
    if (isSeller()) {
        header("Location: dashboard.php");
        exit();
    }
    if (isAdmin()) {
      header("Location: /vinner/admin/admin_dashboard.php");

        exit();
    }
    // Add fallback if no role matches
    header("Location: dashboard.php");
    exit();
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function rateLimitCheck($email, $db) {
    $sql = "SELECT COUNT(*) AS attempts 
            FROM login_attempts 
            WHERE email = ? 
            AND attempt_time > NOW() - INTERVAL 15 MINUTE";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return ($result['attempts'] < 5);
}

function recordLoginAttempt($email, $success, $db) {
    $sql = "INSERT INTO login_attempts (email, success, attempt_ip, user_agent)
            VALUES (?, ?, ?, ?)";

    $stmt = $db->prepare($sql);
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];
    $stmt->bind_param("siss", $email, $success, $ip, $ua);
    $stmt->execute();
}

// Remove old logs
$db->query("DELETE FROM login_attempts WHERE attempt_time < NOW() - INTERVAL 1 HOUR");

// Already logged in?
if (isLoggedIn()) {
    redirectBasedOnRole();
}

/* ----------------------------
           LOGIN LOGIC
-----------------------------*/
$error = "";
$email = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    if (!isset($_POST['csrf_token']) ||
        $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? ''))
    {
        $error = "Invalid form submission.";
    } 
    else 
    {
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            $error = "Please enter both email and password.";
        } 
        elseif (!validateEmail($email)) {
            $error = "Invalid email address.";
        }
        elseif (!rateLimitCheck($email, $db)) {
            $error = "Too many attempts. Try again later.";
        }
        else {
            // Get user
            $sql = "SELECT id, name, email, password, role, is_active
                    FROM users WHERE email = ? LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && $user['is_active'] == 1 && password_verify($password, $user['password'])) {

                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['login_time'] = time();

                // Debug: Check session values
                error_log("Login successful - User ID: " . $user['id'] . ", Role: " . $user['role']);

                // Renew session ID
                session_regenerate_id(true);

                // Log success
                recordLoginAttempt($email, 1, $db);

                // Update last login
                $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update->bind_param("i", $user['id']);
                $update->execute();

                // Immediate redirect
                redirectBasedOnRole();
            } 
            else {
                $error = "Invalid email or password.";
                recordLoginAttempt($email, 0, $db);
                sleep(1);
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vinmel Irrigation Business Management System">
    <meta name="robots" content="noindex, nofollow">
    <title>Vinmel Irrigation - Login</title>
    
    <!-- Security-focused CSP -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:;">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="login-body">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card">
                    <div class="login-header">
                        <h2 class="mb-1">🏪 Vinmel Irrigation</h2>
                        <p class="mb-0">Secure Business Management System</p>
                    </div>
                    
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i> 
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="loginForm" autocomplete="on">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($email); ?>" 
                                           required autocomplete="email" autofocus
                                           placeholder="Enter your email">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           required autocomplete="current-password"
                                           placeholder="Enter your password">
                                    <span class="input-group-text password-toggle" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="passwordIcon"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" name="login" class="btn btn-login btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login to System
                                </button>
                            </div>
                            
                            <div class="security-notice">
                                <i class="fas fa-shield-alt me-1"></i>
                                Secure system access • All activities are logged
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Footer -->
                <div class="security-footer">
                    <small>
                        &copy; <?php echo date('Y'); ?> Vinmel Irrigation. 
                        <br>Protected by advanced security measures.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Font Awesome for icons -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Focus on email field if empty
            const emailField = document.getElementById('email');
            if (!emailField.value) {
                emailField.focus();
            }
        });
        
        // Form submission protection
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Authenticating...';
        });
    </script>
</body>
</html>