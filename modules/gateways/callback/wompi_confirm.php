<?php
/**
 * WHMCS Wompi Payment Gateway Confirmation Landing Page
 *
 * This file redirects the user back to their WHMCS invoice page instantly
 * without loading WHMCS core, maximizing redirection speed and eliminating
 * any potential server-side network latency or global collisions.
 *
 * @developer Cobol Ingeniería SAS
 * @support soporte@cobol.com.co
 * @copyright Copyright (c) 2026
 */

declare(strict_types=1);

$invoiceId = (int)($_GET['invoiceid'] ?? 0);

if ($invoiceId > 0) {
    // Perform an ultra-fast relative redirect to the native WHMCS invoice page (3 folders up)
    header("Location: ../../../viewinvoice.php?id=" . $invoiceId);
    exit;
}

header("Location: ../../../clientarea.php");
exit;
