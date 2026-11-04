<?php
declare(strict_types=1);

/**
 * payments/callback.php
 *
 * Paystack redirect callback after customer payment.
 * Verifies the transaction and activates the subscription.
 */

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\PaymentService;

require_auth();

$reference = trim((string) ($_GET['reference'] ?? ''));
if ($reference === '') {
    header('Location: ' . app_base_url() . '/payments/index.php');
    exit;
}

$payment  = new PaymentService();
$response = $payment->verifyTransaction($reference);

if (!$response || empty($response['data']['status']) || $response['data']['status'] !== 'success') {
    $msg = urlencode('Payment verification failed. Contact support with reference: ' . $reference);
    header('Location: ' . app_base_url() . '/payments/index.php?error=' . $msg);
    exit;
}

$txData    = $response['data'];
$metadata  = $txData['metadata'] ?? [];
$tenantId  = (int) ($metadata['tenant_id'] ?? $_SESSION['tenant_id'] ?? 1);
$planId    = (int) ($metadata['plan_id']   ?? 0);
$amountNgn = (float) $txData['amount'] / 100;
$currency  = (string) ($txData['currency'] ?? 'NGN');

try {
    $subscriptionId = null;
    if ($planId > 0) {
        $subscriptionId = $payment->activateSubscription($tenantId, $planId);
    }
    $payment->recordPayment($tenantId, $subscriptionId, 'paystack', $reference, $amountNgn, $currency, 'paid');
} catch (Throwable $exception) {
    Database::logError('Subscription activation failed after payment', [
        'reference' => $reference,
        'exception' => $exception->getMessage(),
    ]);
}

$msg = urlencode('Payment successful! Your subscription is now active.');
header('Location: ' . app_base_url() . '/index.php?success=' . $msg);
exit;
