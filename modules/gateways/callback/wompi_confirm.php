<?php
/**
 * WHMCS Wompi Payment Gateway Confirmation Landing Page
 *
 * This file handles real-time verification of Wompi transactions and displays
 * a beautiful, modern success/failure landing page before redirecting back to WHMCS.
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

$status = 'PENDING';
$amount = 0.0;
$currency = 'COP';

if (!empty($transactionId)) {
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
            $currency = strtoupper((string)($data['data']['currency'] ?? 'COP'));
        }
    } catch (\Throwable $e) {
        $status = 'PENDING';
    }
}

// Redirect back to the invoice page
$systemUrl = $gatewayParams['systemurl'] ?? '';
$invoiceUrl = rtrim($systemUrl, '/') . '/viewinvoice.php?id=' . $invoiceId;

// Determine details based on status
$statusColor = '#f59e0b'; // Orange
$statusIcon = 'spinner';
$statusTitle = 'Verificando tu Pago';
$statusDesc = 'Estamos consultando el estado de tu pago con Wompi en tiempo real. Por favor no cierres esta pestaña.';

if ($status === 'APPROVED') {
    $statusColor = '#10b981'; // Green
    $statusIcon = 'checkmark';
    $statusTitle = '¡Pago Aprobado con Éxito!';
    $statusDesc = 'Hemos recibido tu pago correctamente. Tu servicio será activado o renovado automáticamente en unos instantes.';
} elseif (in_array($status, ['DECLINED', 'VOIDED', 'ERROR'], true)) {
    $statusColor = '#ef4444'; // Red
    $statusIcon = 'error';
    $statusTitle = 'Pago No Completado';
    $statusDesc = 'La transacción fue declinada por la entidad financiera o cancelada. No se ha realizado ningún cobro.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($statusTitle); ?> - Wompi</title>
    <!-- Modern Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome for fallback icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: <?php echo $statusColor; ?>;
            --bg-gradient-start: #f8fafc;
            --bg-gradient-end: #e2e8f0;
            --text-main: #0f172a;
            --text-sub: #475569;
            --card-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-main);
        }

        .container {
            width: 100%;
            max-width: 540px;
        }

        .card {
            background: #ffffff;
            border-radius: 24px;
            padding: 40px 30px;
            box-shadow: var(--card-shadow);
            text-align: center;
            border: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background-color: var(--primary-color);
        }

        /* Animated Icons container */
        .icon-container {
            width: 100px;
            height: 100px;
            margin: 0 auto 25px auto;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Success Checkmark Animation */
        .checkmark-circle {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background-color: #ecfdf5;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: scaleIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }
        .checkmark-icon {
            color: #10b981;
            font-size: 45px;
            animation: bounceIn 0.8s ease forwards;
        }

        /* Error/Failed Cross Animation */
        .error-circle {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background-color: #fef2f2;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: scaleIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }
        .error-icon {
            color: #ef4444;
            font-size: 45px;
            animation: shake 0.6s ease-in-out forwards;
        }

        /* Spinner / Verification Loader */
        .spinner-circle {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 5px solid #fef3c7;
            border-top-color: #f59e0b;
            animation: rotate 1s linear infinite;
        }

        /* Typography */
        h1 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        p.description {
            font-size: 15px;
            color: var(--text-sub);
            line-height: 1.6;
            margin-bottom: 30px;
        }

        /* Transaction Metadata Table */
        .meta-box {
            background-color: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
            border: 1px solid #f1f5f9;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .meta-row:last-child {
            margin-bottom: 0;
            padding-top: 12px;
            border-top: 1px dashed #e2e8f0;
        }

        .meta-label {
            color: var(--text-sub);
            font-weight: 600;
        }

        .meta-value {
            color: var(--text-main);
            font-weight: 700;
        }

        .meta-row-total .meta-value {
            font-size: 16px;
            color: var(--primary-color);
        }

        /* Progress Bar for Auto Redirect */
        .countdown-container {
            margin-bottom: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .countdown-text {
            font-size: 13px;
            color: var(--text-sub);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .progress-bar {
            width: 100%;
            max-width: 250px;
            height: 5px;
            background-color: #f1f5f9;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background-color: var(--primary-color);
            width: 100%;
            animation: shrink 5s linear forwards;
            transform-origin: left;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: var(--text-main);
            color: #ffffff;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 15px;
            transition: all 0.2s ease;
            width: 100%;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            background-color: #1e293b;
        }

        .btn i {
            margin-right: 8px;
            font-size: 16px;
        }

        /* Branding Footer */
        .footer {
            text-align: center;
            margin-top: 25px;
            font-size: 12px;
            color: var(--text-sub);
            font-weight: 600;
        }
        .footer a {
            color: var(--text-main);
            text-decoration: none;
        }

        /* Keyframe Animations */
        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes rotate {
            100% { transform: rotate(360deg); }
        }

        @keyframes shrink {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <!-- Icon section based on state -->
        <div class="icon-container">
            <?php if ($statusIcon === 'checkmark'): ?>
                <div class="checkmark-circle">
                    <i class="fa-solid fa-circle-check checkmark-icon"></i>
                </div>
            <?php elseif ($statusIcon === 'error'): ?>
                <div class="error-circle">
                    <i class="fa-solid fa-circle-xmark error-icon"></i>
                </div>
            <?php else: ?>
                <div class="spinner-circle"></div>
            <?php endif; ?>
        </div>

        <!-- Title & Subtitle -->
        <h1 style="color: var(--primary-color);"><?php echo htmlspecialchars($statusTitle); ?></h1>
        <p class="description"><?php echo htmlspecialchars($statusDesc); ?></p>

        <!-- Metadata Table -->
        <div class="meta-box">
            <div class="meta-row">
                <span class="meta-label">Factura ID</span>
                <span class="meta-value">#<?php echo $invoiceId; ?></span>
            </div>
            <?php if (!empty($transactionId)): ?>
                <div class="meta-row">
                    <span class="meta-label">Transacción ID</span>
                    <span class="meta-value" style="font-size: 12px; font-family: monospace;"><?php echo htmlspecialchars($transactionId); ?></span>
                </div>
            <?php endif; ?>
            <div class="meta-row">
                <span class="meta-label">Estado de Transacción</span>
                <span class="meta-value" style="color: var(--primary-color); text-transform: uppercase;">
                    <?php 
                        if ($status === 'APPROVED') echo 'Aprobado';
                        elseif (in_array($status, ['DECLINED', 'VOIDED', 'ERROR'], true)) echo 'Rechazado';
                        else echo 'Verificando';
                    ?>
                </span>
            </div>
            <?php if ($amount > 0): ?>
                <div class="meta-row meta-row-total">
                    <span class="meta-label">Total Procesado</span>
                    <span class="meta-value"><?php echo number_format($amount, 2) . ' ' . htmlspecialchars($currency); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Progress bar for automatic redirect -->
        <div class="countdown-container">
            <span class="countdown-text">Redirigiéndote de vuelta en <span id="secs">5</span> segundos...</span>
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
        </div>

        <!-- Return button -->
        <a href="<?php echo htmlspecialchars($invoiceUrl); ?>" class="btn">
            <i class="fa-solid fa-arrow-left"></i> Volver a mi Factura
        </a>
    </div>

    <!-- Branding Footer -->
    <div class="footer">
        Desarrollado y optimizado de forma segura por <a href="https://cobol.com.co" target="_blank"><strong>Cobol Ingeniería SAS</strong></a>
    </div>
</div>

<script>
    // Automatic redirect handling
    const redirectUrl = <?php echo json_encode($invoiceUrl); ?>;
    let secondsLeft = 5;
    const counterSpan = document.getElementById('secs');

    const countdownInterval = setInterval(() => {
        secondsLeft--;
        if (counterSpan) {
            counterSpan.textContent = secondsLeft;
        }
        if (secondsLeft <= 0) {
            clearInterval(countdownInterval);
            window.location.href = redirectUrl;
        }
    }, 1000);
</script>

</body>
</html>
