<?php
/**
 * WHMCS Wompi Payment Gateway Callback Handler
 *
 * This file handles asynchronous webhook events sent by Wompi.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

// Require libraries needed for gateway module functions
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if the module is not active in WHMCS
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve raw request payload
$rawPayload = isset($GLOBALS['mock_webhook_payload']) ? $GLOBALS['mock_webhook_payload'] : file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

// Verify basic payload presence
if (empty($payload) || !isset($payload['event']) || !isset($payload['data']['transaction'])) {
    http_response_code(400);
    die("Invalid Payload");
}

// Determine environment to select the correct Events Secret
$environment = $payload['environment'] ?? 'test';
$eventsSecret = ($environment === 'test') ? ($gatewayParams['eventsSecretTest'] ?? '') : ($gatewayParams['eventsSecretLive'] ?? '');

if (empty($eventsSecret)) {
    logTransaction($gatewayParams['name'], $rawPayload, "Webhook verification failed: Events Secret is not configured for environment: {$environment}");
    http_response_code(400);
    die("Webhook Verification Failed - Configuration Missing");
}

/**
 * Validate Wompi Webhook Signature
 *
 * @param array $payload
 * @param string $eventsSecret
 * @return bool
 */
function validate_wompi_webhook_signature($payload, $eventsSecret) {
    if (!isset($payload['signature']['properties']) || !isset($payload['signature']['checksum']) || !isset($payload['timestamp'])) {
        return false;
    }

    $properties = $payload['signature']['properties'];
    $checksum = $payload['signature']['checksum'];
    $timestamp = $payload['timestamp'];

    // Concatenate property values
    $concat = '';
    foreach ($properties as $prop) {
        // Properties are dot-notated like 'transaction.id', 'transaction.status', etc.
        $parts = explode('.', $prop);
        $val = $payload['data'];
        foreach ($parts as $part) {
            if (is_array($val) && isset($val[$part])) {
                $val = $val[$part];
            } else {
                $val = '';
                break;
            }
        }
        
        // Convert boolean/integers to string representation
        if (is_bool($val)) {
            $concat .= $val ? 'true' : 'false';
        } else {
            $concat .= (string)$val;
        }
    }

    // Append timestamp and events secret
    $concat .= $timestamp . $eventsSecret;

    // Generate SHA256 hash
    $generatedHash = hash('sha256', $concat);

    return hash_equals($checksum, $generatedHash);
}

// Perform webhook signature verification
if (!validate_wompi_webhook_signature($payload, $eventsSecret)) {
    logTransaction($gatewayParams['name'], $rawPayload, "Webhook verification failed: Signature mismatch.");
    http_response_code(401);
    die("Webhook Signature Mismatch");
}

$transaction = $payload['data']['transaction'];
$reference = $transaction['reference'] ?? '';
$transactionId = $transaction['id'] ?? '';
$status = $transaction['status'] ?? '';
$amountInCents = $transaction['amount_in_cents'] ?? 0;
$paymentAmount = $amountInCents / 100;

// Extract Invoice ID from transaction reference (format: invoiceid-timestamp-rand)
$parts = explode('-', $reference);
$invoiceId = (int)($parts[0] ?? 0);

if ($invoiceId <= 0) {
    logTransaction($gatewayParams['name'], $rawPayload, "Webhook failed: Could not parse Invoice ID from reference: {$reference}");
    http_response_code(400);
    die("Invalid Invoice ID in Reference");
}

// Check invoice exists in WHMCS
try {
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
} catch (\Exception $e) {
    logTransaction($gatewayParams['name'], $rawPayload, "Webhook failed: Invoice ID {$invoiceId} check failed: " . $e->getMessage());
    http_response_code(400);
    die("Invoice ID check failed");
}

// Check if transaction has already been processed in WHMCS
try {
    checkCbTransID($transactionId);
} catch (\Exception $e) {
    // If checkCbTransID throws an exception or exits, it means it was already logged/processed.
    // In WHMCS, checkCbTransID handles duplicate transactions automatically.
    http_response_code(200);
    die("Transaction already processed");
}

// Process the payment based on status
if ($status === 'APPROVED') {
    // Mark invoice as paid
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        0, // Gateway fee
        $gatewayModuleName
    );
    logTransaction($gatewayParams['name'], $rawPayload, "Successful webhook payment.");
} elseif (in_array($status, array('DECLINED', 'VOIDED', 'ERROR'))) {
    // Log failed transaction
    logTransaction($gatewayParams['name'], $rawPayload, "Failed webhook payment (Status: {$status}).");
} else {
    // Log other states (e.g. PENDING) without modifying the invoice
    logTransaction($gatewayParams['name'], $rawPayload, "Webhook payment status update (Status: {$status}).");
}

// Respond with 200 OK to acknowledge receipt
http_response_code(200);
echo json_encode(array('status' => 'success'));
