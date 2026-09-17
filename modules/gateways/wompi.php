<?php
/**
 * WHMCS Wompi Payment Gateway Module (Onsite API)
 *
 * This module allows captured credit card payments directly in WHMCS
 * via the Wompi API.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define gateway metadata.
 *
 * @return array
 */
function wompi_MetaData() {
    return array(
        'DisplayName' => 'Wompi API (Onsite)',
        'APIVersion' => '1.1', // Use API Version 1.1
    );
}

/**
 * Define gateway configuration options.
 *
 * @return array
 */
function wompi_config() {
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Wompi API (Onsite)',
        ),
        'publicKeyTest' => array(
            'FriendlyName' => 'Public Key (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox public key (pub_test_...)',
        ),
        'privateKeyTest' => array(
            'FriendlyName' => 'Private Key (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox private key (prv_test_...)',
        ),
        'integritySecretTest' => array(
            'FriendlyName' => 'Integrity Secret (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox integrity secret',
        ),
        'eventsSecretTest' => array(
            'FriendlyName' => 'Events Secret (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox events secret',
        ),
        'publicKeyLive' => array(
            'FriendlyName' => 'Public Key (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production public key (pub_prod_...)',
        ),
        'privateKeyLive' => array(
            'FriendlyName' => 'Private Key (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production private key (prv_prod_...)',
        ),
        'integritySecretLive' => array(
            'FriendlyName' => 'Integrity Secret (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production integrity secret',
        ),
        'eventsSecretLive' => array(
            'FriendlyName' => 'Events Secret (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production events secret',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable sandbox/test mode',
        ),
    );
}

/**
 * Make an HTTP request to Wompi API.
 *
 * @param string $method GET or POST
 * @param string $endpoint The endpoint URL path (e.g. /merchants/info)
 * @param array|null $payload Optional payload for POST requests
 * @param string $bearerToken Authorization Bearer token (Public or Private Key)
 * @param array $extraHeaders Optional extra headers (e.g. x-merchant-public-key)
 * @return array The decoded JSON response
 */
function wompi_api_request($method, $endpoint, $payload = null, $bearerToken = '', $extraHeaders = array()) {
    if (isset($GLOBALS['wompi_api_mock_handler']) && is_callable($GLOBALS['wompi_api_mock_handler'])) {
        return call_user_func($GLOBALS['wompi_api_mock_handler'], $method, $endpoint, $payload, $bearerToken, $extraHeaders);
    }

    $apiUrl = 'https://production.wompi.co/v1';
    
    // We can extract base URL from endpoint if it starts with http, but let's assume it's relative
    $url = (strpos($endpoint, 'http') === 0) ? $endpoint : $apiUrl . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $headers = array(
        'Content-Type: application/json',
    );
    
    if (!empty($bearerToken)) {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }
    
    foreach ($extraHeaders as $k => $v) {
        $headers[] = $k . ': ' . $v;
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
    } elseif (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return array(
            'error' => array(
                'type' => 'CURL_ERROR',
                'reason' => $curlError
            )
        );
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array(
            'error' => array(
                'type' => 'JSON_DECODE_ERROR',
                'reason' => 'Failed to parse JSON response',
                'raw' => $response
            )
        );
    }

    return $decoded;
}

/**
 * Capture payment using Wompi API.
 *
 * @param array $params
 * @return array
 */
function wompi_capture($params) {
    // 1. Determine environment and keys
    $isTest = (!empty($params['testMode']) && ($params['testMode'] === 'on' || $params['testMode'] === '1' || $params['testMode'] === true));
    
    if ($isTest) {
        $publicKey = $params['publicKeyTest'] ?? '';
        $privateKey = $params['privateKeyTest'] ?? '';
        $integritySecret = $params['integritySecretTest'] ?? '';
        $baseUrl = 'https://sandbox.wompi.co/v1';
    } else {
        $publicKey = $params['publicKeyLive'] ?? '';
        $privateKey = $params['privateKeyLive'] ?? '';
        $integritySecret = $params['integritySecretLive'] ?? '';
        $baseUrl = 'https://production.wompi.co/v1';
    }

    // Check if configuration is missing
    if (empty($publicKey) || empty($privateKey)) {
        return array(
            'status' => 'error',
            'rawdata' => 'Wompi module configuration is incomplete. Missing API keys.'
        );
    }

    // 2. Fetch Acceptance Tokens (Habeas Data compliance)
    // We send public key via the specific header as per latest Wompi requirements
    $merchantsEndpoint = $baseUrl . '/merchants/info';
    $merchantResponse = wompi_api_request('GET', $merchantsEndpoint, null, '', array('x-merchant-public-key' => $publicKey));

    if (!isset($merchantResponse['data']['presigned_acceptance']['acceptance_token'])) {
        return array(
            'status' => 'error',
            'rawdata' => 'Failed to fetch Acceptance Token from Wompi: ' . json_encode($merchantResponse)
        );
    }

    $acceptanceToken = $merchantResponse['data']['presigned_acceptance']['acceptance_token'];
    
    // Also fetch accept_personal_auth if available
    $acceptPersonalAuth = '';
    if (isset($merchantResponse['data']['presigned_personal_data_auth']['acceptance_token'])) {
        $acceptPersonalAuth = $merchantResponse['data']['presigned_personal_data_auth']['acceptance_token'];
    }

    // 3. Tokenize Credit Card (using Simple Tokenization over safe server-to-server TLS)
    $cardNum = str_replace(array(' ', '-'), '', $params['cardnum']);
    $cvv = $params['cccvv'];
    $expMonth = substr($params['cardexp'], 0, 2);
    $expYear = substr($params['cardexp'], 2, 2); // 2-digit format, e.g. "25"
    $cardHolder = trim(($params['clientdetails']['firstname'] ?? '') . ' ' . ($params['clientdetails']['lastname'] ?? ''));

    $tokenPayload = array(
        'number' => $cardNum,
        'cvc' => $cvv,
        'exp_month' => $expMonth,
        'exp_year' => $expYear,
        'card_holder' => !empty($cardHolder) ? $cardHolder : 'Tarjetahabiente'
    );

    $tokenEndpoint = $baseUrl . '/tokens/cards';
    // Card tokenization uses Public Key as Bearer token
    $tokenResponse = wompi_api_request('POST', $tokenEndpoint, $tokenPayload, $publicKey);

    if (!isset($tokenResponse['status']) || $tokenResponse['status'] !== 'CREATED' || !isset($tokenResponse['data']['id'])) {
        return array(
            'status' => 'declined',
            'rawdata' => 'Card tokenization failed: ' . json_encode($tokenResponse)
        );
    }

    $cardToken = $tokenResponse['data']['id'];

    // 4. Create Transaction
    $invoiceId = $params['invoiceid'];
    $reference = $invoiceId . '-' . time() . '-' . rand(1000, 9999);
    $amount = $params['amount']; // Float format
    $currency = strtoupper($params['currency']);
    
    // Wompi requires amount in cents. For COP, it has 100 cents per Peso in the API
    $amountInCents = (int) round($amount * 100);

    // Build the request payload
    $transactionPayload = array(
        'amount_in_cents' => $amountInCents,
        'currency' => $currency,
        'customer_email' => $params['clientdetails']['email'] ?? '',
        'payment_method' => array(
            'type' => 'CARD',
            'token' => $cardToken,
            'installments' => 1
        ),
        'reference' => $reference,
        'acceptance_token' => $acceptanceToken
    );

    // If accept_personal_auth is retrieved, pass it as well
    if (!empty($acceptPersonalAuth)) {
        $transactionPayload['accept_personal_auth'] = $acceptPersonalAuth;
    }

    // Generate Integrity Signature if integrity secret is configured
    if (!empty($integritySecret)) {
        // Concatenation: reference + amount_in_cents + currency + integritySecret
        $sigString = $reference . $amountInCents . $currency . $integritySecret;
        $signature = hash('sha256', $sigString);
        $transactionPayload['signature'] = $signature;
    }

    $transactionEndpoint = $baseUrl . '/transactions';
    // Creating transaction uses Private Key as Bearer token
    $txResponse = wompi_api_request('POST', $transactionEndpoint, $transactionPayload, $privateKey);

    if (isset($txResponse['error'])) {
        return array(
            'status' => 'error',
            'rawdata' => 'Transaction creation error: ' . json_encode($txResponse)
        );
    }

    if (!isset($txResponse['data']['id'])) {
        return array(
            'status' => 'declined',
            'rawdata' => 'Failed to initiate transaction: ' . json_encode($txResponse)
        );
    }

    $transactionId = $txResponse['data']['id'];
    $txStatus = $txResponse['data']['status'] ?? 'PENDING';

    // 5. Polling loop if status is PENDING (gives a seamless checkout experience if transaction completes in seconds)
    if ($txStatus === 'PENDING') {
        $maxPolls = 5;
        $pollInterval = 2; // seconds
        
        for ($i = 0; $i < $maxPolls; $i++) {
            sleep($pollInterval);
            
            $pollEndpoint = $baseUrl . '/transactions/' . $transactionId;
            $pollResponse = wompi_api_request('GET', $pollEndpoint, null, $privateKey);
            
            if (isset($pollResponse['data']['status'])) {
                $txStatus = $pollResponse['data']['status'];
                $txResponse = $pollResponse; // Hold onto the latest response details
                if ($txStatus !== 'PENDING') {
                    break;
                }
            }
        }
    }

    // 6. Return appropriate response back to WHMCS
    $rawdata = json_encode($txResponse);

    switch ($txStatus) {
        case 'APPROVED':
            return array(
                'status' => 'success',
                'transid' => $transactionId,
                'rawdata' => $rawdata
            );
        case 'DECLINED':
            return array(
                'status' => 'declined',
                'rawdata' => $rawdata
            );
        case 'PENDING':
            return array(
                'status' => 'pending',
                'transid' => $transactionId,
                'rawdata' => $rawdata
            );
        case 'ERROR':
        default:
            return array(
                'status' => 'error',
                'rawdata' => $rawdata
            );
    }
}
