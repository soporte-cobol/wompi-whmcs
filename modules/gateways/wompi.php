<?php
/**
 * WHMCS Wompi Payment Gateway Module (Web Checkout - Redirect)
 *
 * This module redirects customers to Wompi's secure checkout page
 * to complete payments, supporting Cards, PSE, Nequi, etc.
 *
 * @developer Cobol Ingeniería SAS
 * @support soporte@cobol.com.co
 * @category Payment Gateways (Redirect / Third Party)
 * @copyright Copyright (c) 2026
 * @license MIT
 */

declare(strict_types=1);

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define gateway metadata.
 *
 * @return array<string, string|bool>
 */
function wompi_MetaData(): array {
    return [
        'DisplayName' => 'Wompi Checkout & Modal Widget (Cobol Ingeniería SAS)',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true, // Suppress local credit card forms in WHMCS
    ];
}

/**
 * Define gateway configuration options.
 *
 * @return array<string, array<string, mixed>>
 */
function wompi_config(): array {
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Wompi Web Checkout (Redirect)',
        ],
        'UsageNotes' => [
            'Type' => 'System',
            'Value' => '<strong>Desarrollador:</strong> Cobol Ingeniería SAS<br />' .
                     '<strong>Soporte Técnico:</strong> <a href="mailto:soporte@cobol.com.co">soporte@cobol.com.co</a><br />' .
                     '<strong>Categoría:</strong> Pasarela de Pagos (Redirección / Checkout Externo)<br />' .
                     '<strong>Descripción:</strong> Integración optimizada para procesar pagos seguros con tarjetas de crédito, PSE, Nequi, Bancolombia y más a través de la pasarela Wompi.',
        ],
        'publicKeyTest' => [
            'FriendlyName' => 'Public Key (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox public key (pub_test_...)',
        ],
        'privateKeyTest' => [
            'FriendlyName' => 'Private Key (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox private key (prv_test_...)',
        ],
        'integritySecretTest' => [
            'FriendlyName' => 'Integrity Secret (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox integrity secret',
        ],
        'eventsSecretTest' => [
            'FriendlyName' => 'Events Secret (Test)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi sandbox events secret',
        ],
        'publicKeyLive' => [
            'FriendlyName' => 'Public Key (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production public key (pub_prod_...)',
        ],
        'privateKeyLive' => [
            'FriendlyName' => 'Private Key (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production private key (prv_prod_...)',
        ],
        'integritySecretLive' => [
            'FriendlyName' => 'Integrity Secret (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production integrity secret',
        ],
        'eventsSecretLive' => [
            'FriendlyName' => 'Events Secret (Live)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Wompi production events secret',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable sandbox/test mode',
        ],
    ];
}

/**
 * Generate payment link/form for Wompi Web Checkout.
 *
 * @param array<string, mixed> $params
 * @return string HTML Form string
 */
function wompi_link(array $params): string {
    // 1. Determine environment and keys
    $testMode = $params['testMode'] ?? null;
    $isTest = $testMode === 'on' || $testMode === '1' || $testMode === true;
    
    $publicKey = $isTest ? ($params['publicKeyTest'] ?? '') : ($params['publicKeyLive'] ?? '');
    $integritySecret = $isTest ? ($params['integritySecretTest'] ?? '') : ($params['integritySecretLive'] ?? '');

    if (empty($publicKey)) {
        return '<div class="alert alert-danger">Wompi module configuration is incomplete. Missing Public Key.</div>';
    }

    // 2. Prepare transaction variables
    $invoiceId = (int)($params['invoiceid'] ?? 0);
    $reference = $invoiceId . '-' . time() . '-' . random_int(1000, 9999);
    $amount = (float)($params['amount'] ?? 0.0);
    $currency = strtoupper((string)($params['currency'] ?? 'COP'));
    
    // Wompi amount is in cents
    $amountInCents = (int) round($amount * 100);

    // 3. Generate integrity signature
    $signature = '';
    if (!empty($integritySecret)) {
        $sigString = $reference . $amountInCents . $currency . $integritySecret;
        $signature = hash('sha256', $sigString);
    }

    // Wompi Widget Script URL
    $widgetScriptUrl = 'https://checkout.wompi.co/widget.js';

    // Custom return/redirect url
    $systemUrl = $params['systemurl'] ?? '';
    $customConfirmUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/wompi_confirm.php?invoiceid=' . $invoiceId;

    // Customer details
    $email = $params['clientdetails']['email'] ?? '';
    $firstName = $params['clientdetails']['firstname'] ?? '';
    $lastName = $params['clientdetails']['lastname'] ?? '';
    $fullName = trim($firstName . ' ' . $lastName);
    $phone = $params['clientdetails']['phonenumber'] ?? '';

    // Sanitize phone number for Wompi (+57 prefix + digits)
    $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
    $phonePrefix = '+57'; // Default for Colombia (Wompi country)
    $phoneNumberClean = '';
    
    if (strpos($cleanPhone, '+') === 0) {
        if (strpos($cleanPhone, '+57') === 0) {
            $phonePrefix = '+57';
            $phoneNumberClean = substr($cleanPhone, 3);
        } else {
            // Other international prefix, extract numbers only
            $phoneNumberClean = preg_replace('/[^\d]/', '', $cleanPhone);
        }
    } else {
        $digits = preg_replace('/[^\d]/', '', $cleanPhone);
        if (strlen($digits) === 12 && strpos($digits, '57') === 0) {
            $phonePrefix = '+57';
            $phoneNumberClean = substr($digits, 2);
        } else {
            $phonePrefix = '+57';
            $phoneNumberClean = $digits;
        }
    }
    
    if ($phonePrefix === '+57') {
        $phoneNumberClean = substr($phoneNumberClean, -10);
    }

    $customerData = [
        'email' => $email,
        'fullName' => $fullName
    ];
    if (!empty($phoneNumberClean)) {
        $customerData['phoneNumber'] = $phoneNumberClean;
        $customerData['phoneNumberPrefix'] = $phonePrefix;
    }

    $buttonText = $params['langpaynow'] ?? 'Pagar Ahora con Wompi';

    // Unique button ID to prevent collision if rendered multiple times
    $uniqId = random_int(1000, 9999);

    // 5. Construct HTML Output with the embedded modern Wompi Widget Modal
    $htmlOutput = '
    <!-- Wompi Widget Modal Loader (Cobol Ingeniería SAS) -->
    <script type="text/javascript" src="' . htmlspecialchars($widgetScriptUrl) . '"></script>
    
    <button type="button" class="btn btn-success btn-lg" id="btn_wompi_' . $uniqId . '">
        <i class="fa fa-credit-card"></i> ' . htmlspecialchars($buttonText) . '
    </button>
    
    <script type="text/javascript">
    (function() {
        var btn = document.getElementById("btn_wompi_' . $uniqId . '");
        if (btn) {
            btn.addEventListener("click", function() {
                if (typeof WidgetCheckout === "undefined") {
                    alert("La pasarela de pago Wompi aún se está cargando. Por favor, espera un segundo e intenta de nuevo.");
                    return;
                }
                
                var checkout = new WidgetCheckout({
                    currency: ' . json_encode($currency, JSON_THROW_ON_ERROR) . ',
                    amountInCents: ' . $amountInCents . ',
                    reference: ' . json_encode($reference, JSON_THROW_ON_ERROR) . ',
                    publicKey: ' . json_encode($publicKey, JSON_THROW_ON_ERROR) . ',
                    signature: {
                        integrity: ' . json_encode($signature, JSON_THROW_ON_ERROR) . '
                    },
                    redirectUrl: ' . json_encode($customConfirmUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . ',
                    customerData: ' . json_encode($customerData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . '
                });

                checkout.open(function(result) {
                    // El widget redirecciona automáticamente al terminar
                });
            });
        }
    })();
    </script>
    ';

    return $htmlOutput;
}
