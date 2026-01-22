<?php
// security.php

// Initialize session only once
function initSecureSession() {
    $sessionStatus = session_status();
    
    if ($sessionStatus === PHP_SESSION_NONE) {
        // Configure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);
        
        // Only set secure cookie if using HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', 1);
        }
        
        ini_set('session.cookie_samesite', 'Strict');
        
        
        // Initialize CSRF token if not exists
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        // Mark session as initialized
        $_SESSION['session_initialized'] = true;
        
        return true;
    }
    
    return false; // Session was already started
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// Input Validation Functions
function sanitizeInput($input, $type = 'string') {
    if (is_array($input)) {
        return array_map(function($item) use ($type) {
            return sanitizeInput($item, $type);
        }, $input);
    }
    
    if (!is_string($input) && !is_numeric($input)) {
        return $input;
    }
    
    $input = trim(strval($input));
    
    switch ($type) {
        case 'int':
            return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return (float)filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'string':
        default:
            // Remove tags and encode special characters
            return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
    }
}

function validateInput($input, $rules) {
    $errors = [];
    
    foreach ($rules as $field => $rule) {
        if (!isset($input[$field])) {
            $input[$field] = '';
        }
        
        $value = $input[$field];
        $rulesArray = explode('|', $rule);
        
        foreach ($rulesArray as $singleRule) {
            if ($singleRule === 'required' && (empty($value) && $value !== '0')) {
                $errors[$field] = ucfirst($field) . ' is required';
                break;
            }
            
            if (strpos($singleRule, 'min:') === 0) {
                $min = intval(str_replace('min:', '', $singleRule));
                if (strlen(strval($value)) < $min) {
                    $errors[$field] = ucfirst($field) . " must be at least $min characters";
                }
            }
            
            if (strpos($singleRule, 'max:') === 0) {
                $max = intval(str_replace('max:', '', $singleRule));
                if (strlen(strval($value)) > $max) {
                    $errors[$field] = ucfirst($field) . " must be less than $max characters";
                }
            }
            
            if ($singleRule === 'numeric' && !is_numeric($value)) {
                $errors[$field] = ucfirst($field) . ' must be a number';
            }
            
            if ($singleRule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = 'Invalid email address';
            }
        }
    }
    
    return $errors;
}

// Rate Limiting Function
function checkRateLimit($key, $limit = 10, $timeframe = 60) {
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    $currentTime = time();
    $key = "rate_limit_$key";
    
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = [
            'attempts' => 1,
            'first_attempt' => $currentTime
        ];
        return true;
    }
    
    $rateData = $_SESSION['rate_limit'][$key];
    
    if ($currentTime - $rateData['first_attempt'] > $timeframe) {
        // Reset if timeframe has passed
        $_SESSION['rate_limit'][$key] = [
            'attempts' => 1,
            'first_attempt' => $currentTime
        ];
        return true;
    }
    
    if ($rateData['attempts'] >= $limit) {
        return false; // Rate limit exceeded
    }
    
    $_SESSION['rate_limit'][$key]['attempts']++;
    return true;
}

// XSS Protection Headers
function setSecurityHeaders() {
    if (!headers_sent()) {
        header("X-Frame-Options: DENY");
        header("X-XSS-Protection: 1; mode=block");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        
        // Content Security Policy - adjust based on your needs
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "img-src 'self' data: https:",
            "font-src 'self' https://cdnjs.cloudflare.com",
            "connect-src 'self'"
        ];
        header("Content-Security-Policy: " . implode("; ", $csp));
        
        // Prevent caching of sensitive pages
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
}

// SQL Injection Protection
function prepareStatement($conn, $sql, $params = [], $types = "") {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    return $stmt;
}

// File Upload Validation
function validateFileUpload($file, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'], $maxSize = 5242880) {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
        return $errors;
    }
    
    if ($file['size'] > $maxSize) {
        $errors[] = "File size exceeds maximum allowed size (" . ($maxSize / 1024 / 1024) . "MB)";
    }
    
    // Get MIME type using finfo if available
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $errors[] = "Invalid file type. Allowed types: " . implode(', ', $allowedTypes);
        }
    }
    
    // Check file extension as additional security
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    if (!in_array($extension, $allowedExtensions)) {
        $errors[] = "Invalid file extension. Allowed: " . implode(', ', $allowedExtensions);
    }
    
    return $errors;
}

// Logging Function with improved error handling
function securityLog($message, $level = 'INFO', $user_id = null) {
    $logDir = __DIR__;
    $logFile = $logDir . '/security.log';
    
    // Ensure log directory is writable
    if (!is_writable($logDir)) {
        // Try to fix permissions
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        } else {
            chmod($logDir, 0755);
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? 'GUEST');
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
    
    $logMessage = "[$timestamp] [$level] [IP:$ip] [User:$user_id] [URI:$requestUri] $message\n";
    
    // Try multiple methods to write log
    try {
        // Method 1: Direct file write
        $result = @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            // Method 2: Try alternative location
            $altLog = '/tmp/vinmel_security_' . date('Y-m-d') . '.log';
            @file_put_contents($altLog, $logMessage, FILE_APPEND | LOCK_EX);
            
            // Method 3: Use error_log
            error_log("SECURITY_LOG: " . trim($logMessage));
        }
    } catch (Exception $e) {
        // Final fallback
        error_log("Security log failed: " . $e->getMessage() . " | Original: " . $message);
    }
}

// Simplified sanitization for quick use
function clean($data) {
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    
    if (!is_string($data)) {
        return $data;
    }
    
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Validate integer
function validateInt($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    $intValue = intval($value);
    
    if ($min !== null && $intValue < $min) {
        return false;
    }
    
    if ($max !== null && $intValue > $max) {
        return false;
    }
    
    return true;
}

// Validate float
function validateFloat($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    $floatValue = floatval($value);
    
    if ($min !== null && $floatValue < $min) {
        return false;
    }
    
    if ($max !== null && $floatValue > $max) {
        return false;
    }
    
    return true;
}

// Session validation function
function validateSession() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    // Check for session hijacking
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (!isset($_SESSION['user_ip'])) {
        $_SESSION['user_ip'] = $current_ip;
    }
    
    if (!isset($_SESSION['user_agent'])) {
        $_SESSION['user_agent'] = $current_ua;
    }
    
    // Verify IP and User Agent (allow some flexibility for proxies)
    $ip_match = $_SESSION['user_ip'] === $current_ip;
    $ua_match = $_SESSION['user_agent'] === $current_ua;
    
    if (!$ip_match || !$ua_match) {
        securityLog("Session validation failed: IP or UA mismatch", 'WARNING');
        return false;
    }
    
    // Check session expiration (1 hour)
    if (!isset($_SESSION['login_time'])) {
        return false;
    }
    
    if (time() - $_SESSION['login_time'] > 3600) {
        return false;
    }
    
    // Update last activity
    $_SESSION['login_time'] = time();
    
    return true;
}

// Add this function to check if user is logged in
function isLoggedIn() {
    return validateSession() && isset($_SESSION['user_id']);
}

// Add this function to check if user is admin
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && 
           ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin');
}

// Function to safely destroy session
function destroySession() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

// Function to check and create necessary files
function checkSecurityFiles() {
    $logFile = __DIR__ . '/security.log';
    $errorLog = __DIR__ . '/php_errors.log';
    
    // Create security.log if it doesn't exist
    if (!file_exists($logFile)) {
        $handle = fopen($logFile, 'w');
        if ($handle) {
            fwrite($handle, "[" . date('Y-m-d H:i:s') . "] [INFO] Security log initialized\n");
            fclose($handle);
            chmod($logFile, 0644);
        }
    }
    
    // Create php_errors.log if it doesn't exist
    if (!file_exists($errorLog)) {
        $handle = fopen($errorLog, 'w');
        if ($handle) {
            fwrite($handle, "[" . date('Y-m-d H:i:s') . "] PHP Error Log initialized\n");
            fclose($handle);
            chmod($errorLog, 0644);
        }
    }
    
    return [file_exists($logFile), file_exists($errorLog)];
}

// Initialize security check on include
checkSecurityFiles();
?>