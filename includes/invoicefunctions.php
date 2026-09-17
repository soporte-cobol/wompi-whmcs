<?php
/**
 * Mock WHMCS invoicefunctions.php for testing
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway) {
    $GLOBALS['mock_added_payments'][] = array(
        'invoiceid' => $invoiceId,
        'transid' => $transId,
        'amount' => $amount,
        'fee' => $fee,
        'gateway' => $gateway
    );
    
    // Also save to external file for subprocess communication
    $logFile = dirname(__DIR__) . '/tests/test_logs.json';
    $logs = array();
    if (file_exists($logFile)) {
        $logs = json_decode(file_get_contents($logFile), true) ?: array();
    }
    $logs['added_payments'][] = array(
        'invoiceid' => $invoiceId,
        'transid' => $transId,
        'amount' => $amount,
        'fee' => $fee,
        'gateway' => $gateway
    );
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}
