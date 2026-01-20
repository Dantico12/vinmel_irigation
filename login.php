<?php
session_start();

// Strict error handling for production
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Enhanced Security Headers (OWASP A05:2021 - Security Misconfiguration)
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'; frame-ancestors 'none';");

require_once 'config.php';

// Get DB connection with error handling (OWASP A01:2021 - Broken Access Control)
try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

/* ----------------------------
    SECURITY CONSTANTS
-----------------------------*/
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes in seconds
define('SESSION_TIMEOUT', 3600); // 1 hour
define('PASSWORD_MIN_LENGTH', 8);
define('CSRF_TOKEN_LENGTH', 32);

/* ----------------------------
    AUTH + UTILITY FUNCTIONS
-----------------------------*/

// Enhanced session validation (OWASP A07:2021 - Identification and Authentication Failures)
function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || 
        !isset($_SESSION['user_ip']) || 
        !isset($_SESSION['user_agent']) ||
        !isset($_SESSION['login_time'])) {
        return false;
    }
    
    // IP address validation
    if ($_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR']) {
        session_destroy();
        return false;
    }
    
    // User agent validation
    if ($_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        session_destroy();
        return false;
    }
    
    // Session timeout check
    if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
        session_destroy();
        return false;
    }
    
    // Update last activity
    $_SESSION['login_time'] = time();
    
    return true;
}

function isSeller() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

function redirectBasedOnRole() {
    if (isSeller()) {
        header("Location: dashboard.php");
        exit();
    }
    if (isAdmin()) {
        header("Location: /irrigation/vinmel_irigation/admin/admin_dashboard.php");
        exit();
    }
    header("Location: dashboard.php");
    exit();
}

// Enhanced input sanitization (OWASP A03:2021 - Injection)
function sanitizeInput($data) {
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

function validateEmail($email) {
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    // Additional length check
    if (strlen($email) > 255) {
        return false;
    }
    return true;
}

// Enhanced rate limiting with IP tracking (OWASP A07:2021)
function rateLimitCheck($email, $ip, $db) {
    // Check by email
    $sql = "SELECT COUNT(*) AS attempts 
            FROM login_attempts 
            WHERE email = ? 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            AND success = 0";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("Rate limit check failed: " . $db->error);
        return false;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        return false;
    }
    
    // Check by IP address
    $sql = "SELECT COUNT(*) AS attempts 
            FROM login_attempts 
            WHERE attempt_ip = ? 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            AND success = 0";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return ($result['attempts'] < MAX_LOGIN_ATTEMPTS);
}

// Enhanced login attempt recording with more details
function recordLoginAttempt($email, $success, $db) {
    $sql = "INSERT INTO login_attempts (email, success, attempt_ip, user_agent, attempt_time)
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("Failed to record login attempt: " . $db->error);
        return;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = substr($_SERVER['HTTP_USER_AGENT'], 0, 255); // Limit length
    $stmt->bind_param("siss", $email, $success, $ip, $ua);
    $stmt->execute();
}

// Password strength validation
function validatePasswordStrength($password) {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return false;
    }
    // Add more complexity rules as needed
    return true;
}

// Secure CSRF token generation (OWASP A01:2021)
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || 
        empty($_SESSION['csrf_token_time']) || 
        time() - $_SESSION['csrf_token_time'] > 3600) {
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    // Use hash_equals to prevent timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Clean old login attempts (prevent database bloat)
$cleanup_sql = "DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
$db->query($cleanup_sql);

// Already logged in?
if (isLoggedIn()) {
    redirectBasedOnRole();
}

/* ----------------------------
           LOGIN LOGIC
-----------------------------*/
$error = "";
$email = "";
$showCaptcha = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    
    // CSRF validation (OWASP A01:2021)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF token validation failed for IP: " . $_SERVER['REMOTE_ADDR']);
        $error = "Invalid form submission. Please try again.";
    } 
    else 
    {
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Input validation
        if (empty($email) || empty($password)) {
            $error = "Please enter both email and password.";
        } 
        elseif (!validateEmail($email)) {
            $error = "Invalid email address format.";
            recordLoginAttempt($email, 0, $db);
        }
        elseif (strlen($password) > 255) {
            $error = "Invalid password.";
            recordLoginAttempt($email, 0, $db);
        }
        elseif (!rateLimitCheck($email, $_SERVER['REMOTE_ADDR'], $db)) {
            $error = "Too many failed login attempts. Please try again in 15 minutes.";
            error_log("Rate limit exceeded for email: $email, IP: " . $_SERVER['REMOTE_ADDR']);
        }
        else {
            // Parameterized query to prevent SQL injection (OWASP A03:2021)
            $sql = "SELECT id, name, email, password, role, is_active, failed_login_attempts, account_locked_until
                    FROM users WHERE email = ? LIMIT 1";
            
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                error_log("Database prepare failed: " . $db->error);
                $error = "System error. Please try again later.";
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                
                // Check account lockout
                if ($user && $user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                    $error = "Account is temporarily locked. Please try again later.";
                    recordLoginAttempt($email, 0, $db);
                }
                // Verify user exists, is active, and password is correct
                elseif ($user && $user['is_active'] == 1 && password_verify($password, $user['password'])) {
                    
                    // Regenerate session ID to prevent session fixation (OWASP A07:2021)
                    session_regenerate_id(true);
                    
                    // Set secure session variables
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    
                    // Generate new CSRF token for authenticated session
                    generateCSRFToken();
                    
                    // Log successful login
                    error_log("Successful login - User ID: " . $user['id'] . ", Role: " . $user['role'] . ", IP: " . $_SERVER['REMOTE_ADDR']);
                    recordLoginAttempt($email, 1, $db);
                    
                    // Reset failed login attempts
                    $reset_sql = "UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL, last_login = NOW() WHERE id = ?";
                    $reset_stmt = $db->prepare($reset_sql);
                    $reset_stmt->bind_param("i", $user['id']);
                    $reset_stmt->execute();
                    
                    // Redirect immediately
                    redirectBasedOnRole();
                } 
                else {
                    // Generic error message to prevent user enumeration (OWASP A07:2021)
                    $error = "Invalid email or password.";
                    
                    // Record failed attempt
                    recordLoginAttempt($email, 0, $db);
                    
                    // Update failed login attempts counter
                    if ($user) {
                        $failed_attempts = ($user['failed_login_attempts'] ?? 0) + 1;
                        
                        if ($failed_attempts >= MAX_LOGIN_ATTEMPTS) {
                            $lockout_until = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
                            $update_sql = "UPDATE users SET failed_login_attempts = ?, account_locked_until = ? WHERE id = ?";
                            $update_stmt = $db->prepare($update_sql);
                            $update_stmt->bind_param("isi", $failed_attempts, $lockout_until, $user['id']);
                        } else {
                            $update_sql = "UPDATE users SET failed_login_attempts = ? WHERE id = ?";
                            $update_stmt = $db->prepare($update_sql);
                            $update_stmt->bind_param("ii", $failed_attempts, $user['id']);
                        }
                        $update_stmt->execute();
                    }
                    
                    // Add delay to prevent brute force (timing attack mitigation)
                    usleep(rand(500000, 1500000)); // 0.5-1.5 seconds random delay
                }
            }
        }
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vinmel Irrigation Business Management System - Secure Login">
    <meta name="robots" content="noindex, nofollow">
    <title>Vinmel Irrigation - Secure Login</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous">
    <link href="style.css" rel="stylesheet">
</head>
<body class="login-body">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card shadow">
                    <div class="login-header">
                        <h2 class="mb-1">🏪 Vinmel Irrigation</h2>
                        <p class="mb-0">Secure Business Management System</p>
                    </div>
                    
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i> 
                                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="loginForm" autocomplete="on" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" 
                                           required autocomplete="email" autofocus
                                           maxlength="255"
                                           placeholder="Enter your email"
                                           aria-label="Email address"
                                           aria-required="true">
                                </div>
                                <div class="invalid-feedback">
                                    Please enter a valid email address.
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
                                           maxlength="255"
                                           placeholder="Enter your password"
                                           aria-label="Password"
                                           aria-required="true">
                                    <button class="btn btn-outline-secondary password-toggle" type="button" 
                                            onclick="togglePassword()" aria-label="Toggle password visibility">
                                        <i class="fas fa-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback">
                                    Please enter your password.
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" name="login" class="btn btn-login btn-lg" id="loginBtn">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login to System
                                </button>
                            </div>
                            
                            <div class="security-notice text-center">
                                <i class="fas fa-shield-alt me-1"></i>
                                <small>Secure system access • All activities are logged</small>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Footer -->
                <div class="security-footer text-center mt-3">
                    <small class="text-muted">
                        &copy; <?php echo date('Y'); ?> Vinmel Irrigation. 
                        <br>Protected by OWASP Top 10 security measures.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js" integrity="sha512-yFjZbTYRCJodnuyGlsKamNE/LlEaEAxSUDe5+u61mV8zzqJVFOH7TnULE2/PP/l5vKWpUNnF4VGVkXh3MjgLsg==" crossorigin="anonymous"></script>
    
    <script>
        'use strict';
        
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
        
        // Enhanced form validation
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            const loginBtn = document.getElementById('loginBtn');
            
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }, 7000);
            });
            
            // Email validation
            emailField.addEventListener('blur', function() {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (this.value && !emailRegex.test(this.value)) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            });
            
            // Form submission protection
            loginForm.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Validate email
                if (!emailField.value || !emailField.checkValidity()) {
                    emailField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    emailField.classList.remove('is-invalid');
                }
                
                // Validate password
                if (!passwordField.value || passwordField.value.length < 1) {
                    passwordField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    passwordField.classList.remove('is-invalid');
                }
                
                if (!isValid) {
                    e.preventDefault();
                    return false;
                }
                
                // Disable button and show loading state
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Authenticating...';
                
                // Re-enable after timeout (in case of server error)
                setTimeout(() => {
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Login to System';
                }, 10000);
            });
            
            // Prevent multiple form submissions
            let formSubmitted = false;
            loginForm.addEventListener('submit', function(e) {
                if (formSubmitted) {
                    e.preventDefault();
                    return false;
                }
                formSubmitted = true;
            });
            
            // Clear password on back navigation
            window.addEventListener('pageshow', function(event) {
                if (event.persisted) {
                    passwordField.value = '';
                }
            });
        });
        
        // Prevent console access in production
        if (typeof console !== "undefined") {
            console.log("%cSecurity Warning!", "color: red; font-size: 40px; font-weight: bold;");
            console.log("%cDo not paste any code here. This could compromise your account.", "font-size: 16px;");
        }
    </script>
</body>
</html>