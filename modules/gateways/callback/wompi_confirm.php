<?php
/**
 * WHMCS Wompi Payment Gateway Confirmation Landing Page
 *
 * This file verifies the Wompi transaction in real-time, registers the payment
 * if successful, and immediately redirects the user back to their WHMCS invoice.
 *
 * @developer Cobol Ingeniería SAS
 * @support soporte@cobol.com.co
 * @copyright Copyright (c) 2026
 */

declare(strict_types=1);

// Load WHMCS core
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = 'wompi';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(400);
    die("Module Not Activated");
}

$invoiceId = (int)($_GET['invoiceid'] ?? 0);
$transactionId = (string)($_GET['id'] ?? '');

$systemUrl = $gatewayParams['systemurl'] ?? '';
$invoiceUrl = rtrim($systemUrl, '/') . '/viewinvoice.php?id=' . $invoiceId;

// OPTIMIZATION 1: Local-first check
// If the invoice is already paid (e.g. because the webhook has already processed the payment),
// we can skip the slow Wompi API check completely and redirect the user instantly in milliseconds!
if ($invoiceId > 0) {
    try {
        $invoiceStatus = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if (is_string($invoiceStatus) && strtolower($invoiceStatus) === 'paid') {
            header("Location: " . $invoiceUrl);
            exit;
        }
    } catch (\Throwable $dbEx) {
        // Fallback to API check if database query fails
    }
}

$testMode = $gatewayParams['testMode'] ?? null;
$isTest = ($testMode === 'on' || $testMode === '1' || $testMode === true);
$publicKey = $isTest ? ($gatewayParams['publicKeyTest'] ?? '') : ($gatewayParams['publicKeyLive'] ?? '');

// OPTIMIZATION 2: High-speed cURL fallback
// If the invoice is not paid yet (e.g. localhost or webhook lag), use native cURL instead of
// file_get_contents to significantly speed up SSL handshake, DNS resolution, and connection speeds.
if (!empty($transactionId) && $invoiceId > 0) {
    $apiUrl = $isTest 
        ? "https://sandbox.wompi.co/v1/transactions/{$transactionId}" 
        : "https://production.wompi.co/v1/transactions/{$transactionId}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Strict 2-second timeout to keep it highly responsive
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$publicKey}"
    ]);
    
    try {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode === 200) {
            $data = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
            $status = strtoupper((string)($data['data']['status'] ?? 'PENDING'));
            $amountInCents = (int)($data['data']['amount_in_cents'] ?? 0);
            $amount = $amountInCents / 100;

            if ($status === 'APPROVED') {
                // Dynamically load invoice functions to register payment
                require_once __DIR__ . '/../../../includes/invoicefunctions.php';
                try {
                    $checkedInvoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
                    
                    // Verify if transaction is already processed in WHMCS
                    checkCbTransID($transactionId);
                    
                    // Mark invoice as paid
                    addInvoicePayment(
                        $checkedInvoiceId,
                        $transactionId,
                        $amount,
                        0, // Gateway fee
                        $gatewayModuleName
                    );
                    logTransaction($gatewayParams['name'], (string)$response, "Successful real-time landing page payment.");
                } catch (\Throwable $ex) {
                    // Already processed or invoice is already paid/invalid, ignore safely
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore API/network errors and proceed to redirect
    }
}

// Redirect back to the native WHMCS invoice page immediately
header("Location: " . $invoiceUrl);
exit;
