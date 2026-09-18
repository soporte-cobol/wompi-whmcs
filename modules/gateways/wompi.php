<?php
/**
 * WHMCS Wompi Payment Gateway Module (Web Checkout - Redirect)
 *
 * This module redirects customers to Wompi's secure checkout page
 * to complete payments, supporting Cards, PSE, Nequi, etc.
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
        'DisplayName' => 'Wompi Web Checkout (Redirect)',
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
            'Value' => 'Wompi Web Checkout (Redirect)',
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
 * Generate payment link/form for Wompi Web Checkout.
 *
 * @param array $params
 * @return string HTML Form string
 */
function wompi_link($params) {
    // 1. Determine environment and keys
    $isTest = (!empty($params['testMode']) && ($params['testMode'] === 'on' || $params['testMode'] === '1' || $params['testMode'] === true));
    
    if ($isTest) {
        $publicKey = $params['publicKeyTest'] ?? '';
        $integritySecret = $params['integritySecretTest'] ?? '';
    } else {
        $publicKey = $params['publicKeyLive'] ?? '';
        $integritySecret = $params['integritySecretLive'] ?? '';
    }

    if (empty($publicKey)) {
        return '<div class="alert alert-danger">Wompi module configuration is incomplete. Missing Public Key.</div>';
    }

    // 2. Prepare transaction variables
    $invoiceId = $params['invoiceid'];
    $reference = $invoiceId . '-' . time() . '-' . rand(1000, 9999);
    $amount = $params['amount']; // Float, e.g. 150.00
    $currency = strtoupper($params['currency']);
    
    // Wompi amount is in cents
    $amountInCents = (int) round($amount * 100);

    // 3. Generate integrity signature
    $signature = '';
    if (!empty($integritySecret)) {
        // Concatenation: reference + amount_in_cents + currency + integritySecret
        $sigString = $reference . $amountInCents . $currency . $integritySecret;
        $signature = hash('sha256', $sigString);
    }

    // Wompi Web Checkout URL
    $checkoutUrl = 'https://checkout.wompi.co/p/';

    // 4. Form inputs
    $inputs = array(
        'public-key' => $publicKey,
        'currency' => $currency,
        'amount-in-cents' => $amountInCents,
        'reference' => $reference,
    );

    if (!empty($signature)) {
        $inputs['signature:integrity'] = $signature;
    }

    // Add optional fields
    if (!empty($params['returnurl'])) {
        $inputs['redirect-url'] = $params['returnurl'];
    }

    $email = $params['clientdetails']['email'] ?? '';
    if (!empty($email)) {
        $inputs['customer-data:email'] = $email;
    }

    $firstName = $params['clientdetails']['firstname'] ?? '';
    $lastName = $params['clientdetails']['lastname'] ?? '';
    $fullName = trim($firstName . ' ' . $lastName);
    if (!empty($fullName)) {
        $inputs['customer-data:full-name'] = $fullName;
    }

    $phone = $params['clientdetails']['phonenumber'] ?? '';
    if (!empty($phone)) {
        $inputs['customer-data:phone-number'] = $phone;
    }

    // 5. Construct HTML Output
    $htmlOutput = '<form method="GET" action="' . htmlspecialchars($checkoutUrl) . '">';
    foreach ($inputs as $key => $val) {
        $htmlOutput .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($val) . '" />';
    }
    
    // Modern styled checkout button matching Bootstrap / WHMCS theme
    $buttonText = $params['langpaynow'] ?? 'Pagar Ahora con Wompi';
    $htmlOutput .= '<button type="submit" class="btn btn-success btn-lg">';
    $htmlOutput .= '<i class="fa fa-credit-card"></i> ' . htmlspecialchars($buttonText);
    $htmlOutput .= '</button>';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
