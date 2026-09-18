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

$gatewayModuleName = 'wompi';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(400);
    die("Module Not Activated");
}

$invoiceId = (int)($_GET['invoiceid'] ?? 0);
$transactionId = (string)($_GET['id'] ?? '');

$testMode = $gatewayParams['testMode'] ?? null;
$isTest = ($testMode === 'on' || $testMode === '1' || $testMode === true);
$publicKey = $isTest ? ($gatewayParams['publicKeyTest'] ?? '') : ($gatewayParams['publicKeyLive'] ?? '');

if (!empty($transactionId) && $invoiceId > 0) {
    $apiUrl = $isTest 
        ? "https://sandbox.wompi.co/v1/transactions/{$transactionId}" 
        : "https://production.wompi.co/v1/transactions/{$transactionId}";

    // Set short timeout to keep user experience responsive
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "Authorization: Bearer {$publicKey}\r\n",
            "timeout" => 3.0
        ]
    ];
    $context = stream_context_create($opts);
    try {
        $response = @file_get_contents($apiUrl, false, $context);
        if ($response !== false) {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
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
$systemUrl = $gatewayParams['systemurl'] ?? '';
$invoiceUrl = rtrim($systemUrl, '/') . '/viewinvoice.php?id=' . $invoiceId;

header("Location: " . $invoiceUrl);
exit;
