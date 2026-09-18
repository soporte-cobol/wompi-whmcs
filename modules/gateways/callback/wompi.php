<?php
/**
 * WHMCS Wompi Payment Gateway Callback Handler
 *
 * This file handles asynchronous webhook events sent by Wompi.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

declare(strict_types=1);

// Require libraries needed for gateway module functions
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if the module is not active in WHMCS
if (empty($gatewayParams['type'])) {
    http_response_code(400);
    die("Module Not Activated");
}

// Retrieve raw request payload (uses global mock hook in test mode)
$rawPayload = $GLOBALS['mock_webhook_payload'] ?? file_get_contents('php://input');

try {
    $payload = json_decode((string)$rawPayload, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(400);
    die("Invalid JSON Payload");
}

// Verify basic payload presence
if (empty($payload) || !isset($payload['event'], $payload['data']['transaction'])) {
    http_response_code(400);
    die("Invalid Payload Structure");
}

// Determine environment to select the correct Events Secret
$environment = $payload['environment'] ?? 'test';
$eventsSecret = $environment === 'test'
    ? ($gatewayParams['eventsSecretTest'] ?? '')
    : ($gatewayParams['eventsSecretLive'] ?? '');

if (empty($eventsSecret)) {
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Webhook verification failed: Events Secret is not configured for environment: {$environment}");
    http_response_code(400);
    die("Webhook Verification Failed - Configuration Missing");
}

if (!function_exists('validate_wompi_webhook_signature')) {
    /**
     * Validate Wompi Webhook Signature
     *
     * @param array<string, mixed> $payload
     * @param string $eventsSecret
     * @return bool
     */
    function validate_wompi_webhook_signature(array $payload, string $eventsSecret): bool {
        if (!isset($payload['signature']['properties'], $payload['signature']['checksum'], $payload['timestamp'])) {
            return false;
        }

        $properties = $payload['signature']['properties'];
        $checksum = $payload['signature']['checksum'];
        $timestamp = $payload['timestamp'];

        if (!is_array($properties)) {
            return false;
        }

        // Concatenate property values using elegant dot-notation resolution
        $concat = '';
        foreach ($properties as $prop) {
            $val = array_reduce(
                explode('.', (string)$prop),
                fn($carry, $part) => (is_array($carry) && isset($carry[$part])) ? $carry[$part] : '',
                $payload['data'] ?? []
            );
            
            $concat .= is_bool($val) ? ($val ? 'true' : 'false') : (string)$val;
        }

        // Append timestamp and events secret
        $concat .= $timestamp . $eventsSecret;

        // Generate SHA256 hash and compare securely
        $generatedHash = hash('sha256', $concat);

        return hash_equals((string)$checksum, $generatedHash);
    }
}

// Perform webhook signature verification
if (!validate_wompi_webhook_signature($payload, $eventsSecret)) {
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Webhook verification failed: Signature mismatch.");
    http_response_code(401);
    die("Webhook Signature Mismatch");
}

$transaction = $payload['data']['transaction'] ?? [];
$reference = (string)($transaction['reference'] ?? '');
$transactionId = (string)($transaction['id'] ?? '');
$status = (string)($transaction['status'] ?? '');
$amountInCents = (int)($transaction['amount_in_cents'] ?? 0);
$paymentAmount = $amountInCents / 100;

// Extract Invoice ID from transaction reference (format: invoiceid-timestamp-rand)
$parts = explode('-', $reference);
$invoiceId = (int)($parts[0] ?? 0);

if ($invoiceId <= 0) {
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Webhook failed: Could not parse Invoice ID from reference: {$reference}");
    http_response_code(400);
    die("Invalid Invoice ID in Reference");
}

// Check invoice exists in WHMCS
try {
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
} catch (\Exception $e) {
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Webhook failed: Invoice ID {$invoiceId} check failed: " . $e->getMessage());
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
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Successful webhook payment.");
} elseif (in_array($status, ['DECLINED', 'VOIDED', 'ERROR'], true)) {
    // Log failed transaction
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Failed webhook payment (Status: {$status}).");
} else {
    // Log other states (e.g. PENDING) without modifying the invoice
    logTransaction($gatewayParams['name'], (string)$rawPayload, "Webhook payment status update (Status: {$status}).");
}

// Respond with 200 OK to acknowledge receipt
http_response_code(200);
echo json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);
