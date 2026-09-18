<?php
/**
 * Standalone Test Runner for WHMCS Wompi Web Checkout Gateway (Fully Isolated Sandbox)
 */

$sandboxDir = __DIR__ . '/sandbox';

// Helper function to recursively delete directory
function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object)) {
                    rrmdir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

// 1. Setup Isolated Sandbox Environment
rrmdir($sandboxDir);
mkdir($sandboxDir, 0755, true);
mkdir($sandboxDir . '/includes', 0755, true);
mkdir($sandboxDir . '/modules/gateways/callback', 0755, true);

// Create Mock init.php
file_put_contents($sandboxDir . '/init.php', '<?php define("WHMCS", true);');

// Create Mock includes/gatewayfunctions.php
$gatewayfunctionsCode = '<?php
if (!defined("WHMCS")) die();
function getGatewayVariables($moduleName) {
    return $GLOBALS["mock_gateway_variables"][$moduleName] ?? array(
        "type" => "cc",
        "name" => "Wompi Web Checkout (Redirect)",
        "eventsSecretTest" => "test_event_secret_123456789",
        "eventsSecretLive" => "live_event_secret_123456789"
    );
}
function logTransaction($gatewayName, $data, $message) {
    $GLOBALS["mock_logged_transactions"][] = array("gateway" => $gatewayName, "data" => $data, "message" => $message);
    $logFile = ' . var_export($sandboxDir . '/test_logs.json', true) . ';
    $logs = array();
    if (file_exists($logFile)) $logs = json_decode(file_get_contents($logFile), true) ?: array();
    $logs["logged_transactions"][] = array("gateway" => $gatewayName, "data" => $data, "message" => $message);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}
function checkCbInvoiceID($invoiceId, $gatewayName) {
    if (isset($GLOBALS["mock_fail_check_invoice"]) && $GLOBALS["mock_fail_check_invoice"] === true) {
        throw new \Exception("Invoice check failed mock error");
    }
    return $invoiceId;
}
function checkCbTransID($transactionId) {
    if (isset($GLOBALS["mock_duplicate_transaction"]) && $GLOBALS["mock_duplicate_transaction"] === true) {
        throw new \Exception("Duplicate transaction check failed");
    }
    return true;
}';
file_put_contents($sandboxDir . '/includes/gatewayfunctions.php', $gatewayfunctionsCode);

// Create Mock includes/invoicefunctions.php
$invoicefunctionsCode = '<?php
if (!defined("WHMCS")) die();
function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway) {
    $GLOBALS["mock_added_payments"][] = array("invoiceid" => $invoiceId, "transid" => $transId, "amount" => $amount, "fee" => $fee, "gateway" => $gateway);
    $logFile = ' . var_export($sandboxDir . '/test_logs.json', true) . ';
    $logs = array();
    if (file_exists($logFile)) $logs = json_decode(file_get_contents($logFile), true) ?: array();
    $logs["added_payments"][] = array("invoiceid" => $invoiceId, "transid" => $transId, "amount" => $amount, "fee" => $fee, "gateway" => $gateway);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
}';
file_put_contents($sandboxDir . '/includes/invoicefunctions.php', $invoicefunctionsCode);

// Copy actual module files into sandbox to test them
copy(__DIR__ . '/../modules/gateways/wompi.php', $sandboxDir . '/modules/gateways/wompi.php');
copy(__DIR__ . '/../modules/gateways/callback/wompi.php', $sandboxDir . '/modules/gateways/callback/wompi.php');

// Load sandbox files for in-process testing
require_once $sandboxDir . '/init.php';
require_once $sandboxDir . '/includes/gatewayfunctions.php';
require_once $sandboxDir . '/includes/invoicefunctions.php';
require_once $sandboxDir . '/modules/gateways/wompi.php';

$testsPassed = 0;
$testsFailed = 0;

function assert_equal($expected, $actual, $message = "") {
    global $testsPassed, $testsFailed;
    if ($expected === $actual) {
        $testsPassed++;
        echo "  \033[32m[PASS]\033[0m {$message}\n";
    } else {
        $testsFailed++;
        echo "  \033[31m[FAIL]\033[0m {$message}\n";
        echo "    Expected: " . var_export($expected, true) . "\n";
        echo "    Actual:   " . var_export($actual, true) . "\n";
    }
}

// Helper to run sandbox callback subprocess
function run_callback_test($payload, $gatewayVars, $failCheckInvoice = false, $duplicateTx = false) {
    global $sandboxDir;
    $logFile = $sandboxDir . '/test_logs.json';
    if (file_exists($logFile)) {
        @unlink($logFile);
    }

    $bootstrapCode = '<?php
    $GLOBALS["mock_webhook_payload"] = ' . var_export(json_encode($payload), true) . ';
    $GLOBALS["mock_gateway_variables"] = array("wompi" => ' . var_export($gatewayVars, true) . ');
    $GLOBALS["mock_fail_check_invoice"] = ' . ($failCheckInvoice ? 'true' : 'false') . ';
    $GLOBALS["mock_duplicate_transaction"] = ' . ($duplicateTx ? 'true' : 'false') . ';

    register_shutdown_function(function() {
        $code = http_response_code();
        $logFile = ' . var_export($logFile, true) . ';
        $logs = array();
        if (file_exists($logFile)) {
            $logs = json_decode(file_get_contents($logFile), true) ?: array();
        }
        $logs["http_response_code"] = $code;
        file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
    });
    ';
    file_put_contents($sandboxDir . '/callback_bootstrap.php', $bootstrapCode);

    $cmd = 'php -d auto_prepend_file=' . escapeshellarg($sandboxDir . '/callback_bootstrap.php') . ' ' . escapeshellarg($sandboxDir . '/modules/gateways/callback/wompi.php');
    
    $descriptorspec = array(
        0 => array("pipe", "r"),
        1 => array("pipe", "w"),
        2 => array("pipe", "w")
    );
    
    $process = proc_open($cmd, $descriptorspec, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    @unlink($sandboxDir . '/callback_bootstrap.php');

    $logs = array();
    if (file_exists($logFile)) {
        $logs = json_decode(file_get_contents($logFile), true) ?: array();
    }

    return array(
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exitCode' => $exitCode,
        'logs' => $logs
    );
}

echo "\033[36m=== Starting Isolated Sandbox WHMCS Wompi Web Checkout Gateway Unit Tests ===\033[0m\n\n";

// ==========================================
// SECTION 1: Config & Metadata Tests
// ==========================================
echo "\033[33m--- Section 1: Config & Metadata ---\033[0m\n";

$meta = wompi_MetaData();
assert_equal('Wompi Checkout & Modal Widget (Cobol Ingeniería SAS)', $meta['DisplayName'], "Metadata DisplayName matches");
assert_equal('1.1', $meta['APIVersion'], "Metadata APIVersion matches");

$config = wompi_config();
assert_equal('System', $config['FriendlyName']['Type'], "FriendlyName type matches");
assert_equal('yesno', $config['testMode']['Type'], "testMode type matches");


// ==========================================
// SECTION 2: Web Checkout Link Generation Tests
// ==========================================
echo "\n\033[33m--- Section 2: Link Generation ---\033[0m\n";

// Link Gen: Missing Public Key
$paramsMissing = array(
    'publicKeyTest' => '',
    'testMode' => 'on'
);
$res = wompi_link($paramsMissing);
$expectedError = '<div class="alert alert-danger">Wompi module configuration is incomplete. Missing Public Key.</div>';
assert_equal($expectedError, $res, "Link generation fails with HTML error message if public key is missing");

// Setup base params
$params = array(
    'testMode' => 'on',
    'publicKeyTest' => 'pub_test_123',
    'integritySecretTest' => 'test_integrity_123',
    'invoiceid' => 101,
    'amount' => 150.00,
    'currency' => 'COP',
    'returnurl' => 'http://yourwhmcs.com/viewinvoice.php?id=101',
    'clientdetails' => array(
        'firstname' => 'John',
        'lastname' => 'Doe',
        'email' => 'john.doe@example.com',
        'phonenumber' => '3001234567'
    )
);

// Link Gen: Success Widget Validation
$res = wompi_link($params);
assert_equal(true, strpos($res, 'checkout.wompi.co/widget.js') !== false, "Includes correct Wompi Widget JS library link");
assert_equal(true, strpos($res, 'publicKey: "pub_test_123"') !== false, "Includes correct public-key configuration");
assert_equal(true, strpos($res, 'currency: "COP"') !== false, "Includes correct currency configuration");
assert_equal(true, strpos($res, 'amountInCents: 15000') !== false, "Correctly converts amount to cents ($150.00 = 15000 cents)");
assert_equal(true, strpos($res, '/modules/gateways/callback/wompi_confirm.php?invoiceid=101') !== false, "Includes correct custom redirect confirmation URL");
assert_equal(true, strpos($res, '"email":"john.doe@example.com"') !== false, "Includes prefilled email");
assert_equal(true, strpos($res, '"fullName":"John Doe"') !== false, "Includes prefilled full name");
assert_equal(true, strpos($res, '"phoneNumber":"3001234567"') !== false, "Includes prefilled phone number");
assert_equal(true, strpos($res, 'integrity: ') !== false, "Successfully generates and includes integrity signature object key");


// ==========================================
// SECTION 3: Webhook Callback Tests
// ==========================================
echo "\n\033[33m--- Section 3: Webhook Callback ---\033[0m\n";

$gatewayVars = array(
    'type' => 'cc',
    'name' => 'Wompi Web Checkout (Redirect)',
    'eventsSecretTest' => 'test_event_secret_123456789'
);

// Callback: Success (APPROVED)
$webhookPayload = array(
    'event' => 'transaction.updated',
    'environment' => 'test',
    'timestamp' => 1600000000,
    'data' => array(
        'transaction' => array(
            'id' => 'tx_webhook_approved_123',
            'amount_in_cents' => 15000,
            'reference' => '101-999-999',
            'status' => 'APPROVED',
            'currency' => 'COP'
        )
    ),
    'signature' => array(
        'properties' => array('transaction.id', 'transaction.status', 'transaction.amount_in_cents'),
        'checksum' => ''
    )
);
$concatString = 'tx_webhook_approved_123' . 'APPROVED' . '15000' . '1600000000' . 'test_event_secret_123456789';
$webhookPayload['signature']['checksum'] = hash('sha256', $concatString);

$resCallback = run_callback_test($webhookPayload, $gatewayVars);
assert_equal(200, $resCallback['logs']['http_response_code'] ?? 0, "Callback process returns HTTP 200 OK");
assert_equal(true, isset($resCallback['logs']['added_payments'][0]), "Invoice payment was successfully added");
assert_equal(101, $resCallback['logs']['added_payments'][0]['invoiceid'] ?? 0, "Correct Invoice ID was marked as paid");
assert_equal((float)150.00, (float)($resCallback['logs']['added_payments'][0]['amount'] ?? 0.0), "Correct amount was credited to invoice");
assert_equal("Successful webhook payment.", $resCallback['logs']['logged_transactions'][0]['message'] ?? '', "Transaction logged with success status");

// Callback: Signature Mismatch
$webhookPayloadWrongSig = $webhookPayload;
$webhookPayloadWrongSig['signature']['checksum'] = 'wrong_checksum';

$resCallbackWrongSig = run_callback_test($webhookPayloadWrongSig, $gatewayVars);
assert_equal(401, $resCallbackWrongSig['logs']['http_response_code'] ?? 0, "Callback returns HTTP 401 on signature mismatch");
assert_equal("Webhook verification failed: Signature mismatch.", $resCallbackWrongSig['logs']['logged_transactions'][0]['message'] ?? '', "Signature error logged correctly");

// Callback: Declined Payment
$webhookPayloadDeclined = $webhookPayload;
$webhookPayloadDeclined['data']['transaction']['status'] = 'DECLINED';
$concatStringDeclined = 'tx_webhook_approved_123' . 'DECLINED' . '15000' . '1600000000' . 'test_event_secret_123456789';
$webhookPayloadDeclined['signature']['checksum'] = hash('sha256', $concatStringDeclined);

$resCallbackDeclined = run_callback_test($webhookPayloadDeclined, $gatewayVars);
assert_equal(200, $resCallbackDeclined['logs']['http_response_code'] ?? 0, "Callback process returns HTTP 200 OK for declined transactions");
assert_equal(false, isset($resCallbackDeclined['logs']['added_payments'][0]), "Invoice payment was NOT added for declined payments");
assert_equal("Failed webhook payment (Status: DECLINED).", $resCallbackDeclined['logs']['logged_transactions'][0]['message'] ?? '', "Declined transaction logged correctly");

// Callback: Duplicate Transaction
$resCallbackDuplicate = run_callback_test($webhookPayload, $gatewayVars, false, true);
assert_equal(200, $resCallbackDuplicate['logs']['http_response_code'] ?? 0, "Callback process returns HTTP 200 OK for duplicate transaction check");
assert_equal("Transaction already processed", trim($resCallbackDuplicate['stdout']), "Handles duplicate transactions gracefully with standard WHMCS behaviour");

// Callback: Invoice Check Failed
$resCallbackInvoiceFail = run_callback_test($webhookPayload, $gatewayVars, true);
assert_equal(400, $resCallbackInvoiceFail['logs']['http_response_code'] ?? 0, "Callback returns HTTP 400 when WHMCS invoice check fails");
assert_equal("Invoice ID check failed", trim($resCallbackInvoiceFail['stdout']), "Callback output displays invoice check error");


// ==========================================
// FINAL REPORT & CLEANUP
// ==========================================
echo "\n\033[36m=== Final Test Summary ===\033[0m\n";
echo "Total Passed:  \033[32m{$testsPassed}\033[0m\n";
echo "Total Failed:  \033[31m{$testsFailed}\033[0m\n";

// Completely cleanup isolated sandbox directory!
rrmdir($sandboxDir);

if ($testsFailed > 0) {
    exit(1);
} else {
    echo "\n\033[32mAll Wompi Payment Gateway tests have successfully passed (Environment 100% clean)!\033[0m\n";
    exit(0);
}
