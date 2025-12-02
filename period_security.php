<?php
/**
 * PERIOD SECURITY HELPER FUNCTIONS
 * Prevents data alteration in locked periods and validates transactions
 */

// Include central functions
require_once 'functions.php';

/**
 * Check if current period allows modifications
 */
function canModifyData($user_id, $db) {
    $current_period = getCurrentTimePeriod($user_id, $db);
    
    if (!$current_period) {
        return [
            'allowed' => false,
            'message' => "No active time period! Please create or activate a time period first."
        ];
    }
    
    if ($current_period['is_locked']) {
        return [
            'allowed' => false,
            'message' => "Current period is locked! Cannot modify data in locked periods."
        ];
    }
    
    // Check if period has ended
    if (date('Y-m-d') > $current_period['end_date']) {
        return [
            'allowed' => false,
            'message' => "Current period has ended! Please activate a new period."
        ];
    }
    
    return [
        'allowed' => true,
        'message' => "Data modification allowed.",
        'period' => $current_period
    ];
}
/**
 * Check if a specific product can be modified based on its period
 */
function canModifyProduct($product_id, $user_id, $db) {
    $sql = "SELECT p.period_id, p.is_locked, tp.is_locked as period_locked, tp.period_name
            FROM products p 
            LEFT JOIN time_periods tp ON p.period_id = tp.id 
            WHERE p.id = ? AND p.created_by = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $product_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    
    if (!$product) {
        return ['allowed' => false, 'message' => 'Product not found'];
    }
    
    if ($product['is_locked']) {
        return ['allowed' => false, 'message' => 'This product is locked and cannot be modified'];
    }
    
    if ($product['period_locked']) {
        return ['allowed' => false, 'message' => "The period '{$product['period_name']}' for this product is locked"];
    }
    
    return ['allowed' => true, 'message' => 'Product can be modified'];
}
/**
 * Validate if transaction can be created/modified for specific date
 */
function validateTransactionPeriod($transaction_date, $user_id, $db) {
    $current_period = getCurrentTimePeriod($user_id, $db);
    
    if (!$current_period) {
        return [
            'valid' => false,
            'message' => "No active time period! Please create or activate a time period first."
        ];
    }
    
    if ($current_period['is_locked']) {
        return [
            'valid' => false,
            'message' => "Current period is locked! Cannot create or modify transactions in locked periods."
        ];
    }
    
    $transaction_date = date('Y-m-d', strtotime($transaction_date));
    $period_start = $current_period['start_date'];
    $period_end = $current_period['end_date'];
    
    if ($transaction_date < $period_start || $transaction_date > $period_end) {
        return [
            'valid' => false,
            'message' => "Transaction date must be within the current period: " . 
                       date('M j, Y', strtotime($period_start)) . " to " . 
                       date('M j, Y', strtotime($period_end))
        ];
    }
    
    return [
        'valid' => true,
        'message' => "Transaction date is valid.",
        'period' => $current_period
    ];
}

/**
 * Check if we can modify a specific record based on its date
 */
function canModifyRecord($record_date, $user_id, $db) {
    $validation = validateTransactionPeriod($record_date, $user_id, $db);
    return $validation;
}

/**
 * Get current period info for display
 */
function getCurrentPeriodInfo($user_id, $db) {
    $current_period = getCurrentTimePeriod($user_id, $db);
    
    if (!$current_period) {
        return [
            'exists' => false,
            'message' => "No active period",
            'period_name' => "No Active Period",
            'date_range' => "N/A",
            'is_locked' => true
        ];
    }
    
    return [
        'exists' => true,
        'message' => "Current: " . $current_period['period_name'],
        'period_name' => $current_period['period_name'],
        'date_range' => date('M j, Y', strtotime($current_period['start_date'])) . " - " . 
                       date('M j, Y', strtotime($current_period['end_date'])),
        'is_locked' => (bool)$current_period['is_locked'],
        'is_active' => (bool)$current_period['is_active'],
        'period_data' => $current_period
    ];
}

/**
 * Auto-check and redirect if data modification is not allowed
 * Use this at the top of pages that modify data
 */
function enforcePeriodSecurity($user_id, $db, $redirect_url = 'time_periods.php') {
    $security_check = canModifyData($user_id, $db);
    
    if (!$security_check['allowed']) {
        $_SESSION['security_error'] = $security_check['message'];
        header("Location: $redirect_url");
        exit();
    }
    
    return $security_check['period'];
}

/**
 * Show security warning if period is locked or inactive
 * Use this to display warnings on pages
 */
function displayPeriodSecurityWarning($user_id, $db) {
    $period_info = getCurrentPeriodInfo($user_id, $db);
    
    if (!$period_info['exists'] || $period_info['is_locked']) {
        $message = $period_info['exists'] ? 
            "⚠️ Current period is locked. Data modification is disabled." : 
            "⚠️ No active time period. Please create or activate a period.";
            
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ' . $message . '
                <a href="time_periods.php" class="alert-link">Manage Periods</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
    }
}

/**
 * Check if we can proceed with form submission
 * Use this in form processing scripts
 */
function validateFormSubmission($user_id, $db, $date_field = null) {
    // Check general modification permissions
    $modification_check = canModifyData($user_id, $db);
    if (!$modification_check['allowed']) {
        return $modification_check;
    }
    
    // If specific date is provided, validate it
    if ($date_field && isset($_POST[$date_field])) {
        $date_validation = validateTransactionPeriod($_POST[$date_field], $user_id, $db);
        if (!$date_validation['valid']) {
            return $date_validation;
        }
    }
    
    return ['allowed' => true, 'message' => 'Validation passed'];
}

// Auto-check for session security errors and display them
if (isset($_SESSION['security_error'])) {
    $security_error = $_SESSION['security_error'];
    unset($_SESSION['security_error']);
    
    // You can display this error wherever needed, or use a session to pass it
}
?>