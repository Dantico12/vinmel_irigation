<?php
// security.php

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
    
    $input = trim($input);
    
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
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
            if ($singleRule === 'required' && empty($value)) {
                $errors[$field] = ucfirst($field) . ' is required';
                break;
            }
            
            if (strpos($singleRule, 'min:') === 0) {
                $min = intval(str_replace('min:', '', $singleRule));
                if (strlen($value) < $min) {
                    $errors[$field] = ucfirst($field) . " must be at least $min characters";
                }
            }
            
            if (strpos($singleRule, 'max:') === 0) {
                $max = intval(str_replace('max:', '', $singleRule));
                if (strlen($value) > $max) {
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

// XSS Protection Headers - FIXED CSP VERSION
function setSecurityHeaders() {
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // Content Security Policy - FIXED TO ALLOW EXTERNAL RESOURCES
    $csp = [
        "default-src 'self'",
        // Script sources: allow self, CDNs, inline scripts (for now), eval (for jQuery), and blob URLs
        "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline' 'unsafe-eval' blob:",
        // Style sources: allow self, CDNs, and inline styles
        "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'",
        // Image sources: allow self, data URLs, https, and blob URLs
        "img-src 'self' data: https: blob:",
        // Font sources: allow self and CDN
        "font-src 'self' https://cdnjs.cloudflare.com",
        // Connect sources: allow self, CDNs (for map files), and WebSocket
        "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com wss:",
        // Frame sources: allow self
        "frame-src 'self'",
        // Object sources: none (no Flash, Java, etc.)
        "object-src 'none'",
        // Base URI: self only
        "base-uri 'self'",
        // Form actions: self only
        "form-action 'self'",
        // Frame ancestors: none (prevent clickjacking)
        "frame-ancestors 'none'",
        // Upgrade insecure requests
        "upgrade-insecure-requests"
    ];
    
    header("Content-Security-Policy: " . implode("; ", $csp));
}

// Alternative CSP function for development/testing
function setDevelopmentCSP() {
    // Development CSP - More permissive for testing
    $csp = [
        "default-src *",
        "script-src * 'unsafe-inline' 'unsafe-eval' blob:",
        "style-src * 'unsafe-inline'",
        "img-src * data: blob:",
        "font-src * data:",
        "connect-src *",
        "frame-src *",
        "object-src *",
        "base-uri *",
        "form-action *"
    ];
    
    header("Content-Security-Policy: " . implode("; ", $csp));
}

// Generate nonce for inline scripts (more secure alternative to 'unsafe-inline')
function generateCSPNonce() {
    if (!isset($_SESSION['csp_nonce'])) {
        $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}

// Set CSP headers with nonce support
function setSecureCSPWithNonce() {
    $nonce = generateCSPNonce();
    
    $csp = [
        "default-src 'self'",
        "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-eval' blob: 'nonce-$nonce'",
        "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'",
        "img-src 'self' data: https: blob:",
        "font-src 'self' https://cdnjs.cloudflare.com",
        "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com wss:",
        "frame-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "upgrade-insecure-requests"
    ];
    
    header("Content-Security-Policy: " . implode("; ", $csp));
    
    return $nonce;
}

// SQL Injection Protection
function prepareStatement($conn, $sql, $params, $types = "") {
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
        $errors[] = "File size exceeds maximum allowed size";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "Invalid file type";
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    if (!in_array($extension, $allowedExtensions)) {
        $errors[] = "Invalid file extension";
    }
    
    return $errors;
}

// Session Security
function secureSession() {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0); // Enable only if using HTTPS
    ini_set('session.cookie_samesite', 'Strict');
    
    session_regenerate_id(true);
}

// Logging Function
function securityLog($message, $level = 'INFO', $user_id = null) {
    $logFile = __DIR__ . '/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? 'GUEST');
    
    $logMessage = "[$timestamp] [$level] [$ip] [User:$user_id] $message\n";
    
    // Create log file if it doesn't exist
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0644);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Simplified sanitization for quick use
function clean($data) {
    if (is_array($data)) {
        return array_map('clean', $data);
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

// Helper function to get CSP nonce for inline scripts
function getCSPNonce() {
    return generateCSPNonce();
}

// Function to validate and set appropriate CSP based on environment
function setAppropriateCSP($environment = 'production') {
    switch ($environment) {
        case 'development':
            setDevelopmentCSP();
            break;
        case 'staging':
            // Slightly more restrictive than development
            setSecurityHeaders(); // Use the fixed version
            break;
        case 'production':
        default:
            // Most secure - use nonce-based CSP
            return setSecureCSPWithNonce();
    }
    return null;
}

// Function to echo CSP nonce in HTML script tags
function echoCSPNonce() {
    echo 'nonce="' . getCSPNonce() . '"';
}

?>