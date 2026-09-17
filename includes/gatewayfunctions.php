<?php
/**
 * Mock WHMCS gatewayfunctions.php for testing
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function getGatewayVariables($moduleName) {
    return $GLOBALS['mock_gateway_variables'][$moduleName] ?? array(
        'type' => 'cc',
        'name' => 'Wompi API (Onsite)',
        'eventsSecretTest' => 'test_event_secret_123456789',
        'eventsSecretLive' => 'live_event_secret_123456789'
    );
}

function logTransaction($gatewayName, $data, $message) {
    $GLOBALS['mock_logged_transactions'][] = array(
        'gateway' => $gatewayName,
        'data' => $data,
        'message' => $message
    );
    
    // Also save to external file for subprocess communication
    $logFile = dirname(__DIR__) . '/tests/test_logs.json';
    $logs = array();
    if (file_exists($logFile)) {
        $logs = json_decode(file_get_contents($logFile), true) ?: array();
    }
    $logs['logged_transactions'][] = array(
        'gateway' => $gatewayName,
        'data' => $data,
        'message' => $message
    );
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}

function checkCbInvoiceID($invoiceId, $gatewayName) {
    if (isset($GLOBALS['mock_fail_check_invoice']) && $GLOBALS['mock_fail_check_invoice'] === true) {
        throw new \Exception("Invoice check failed mock error");
    }
    return $invoiceId;
}

function checkCbTransID($transactionId) {
    if (isset($GLOBALS['mock_duplicate_transaction']) && $GLOBALS['mock_duplicate_transaction'] === true) {
        throw new \Exception("Duplicate transaction check failed");
    }
    return true;
}
