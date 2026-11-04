<?php
declare(strict_types=1);

/**
 * payments/webhook.php
 *
 * Paystack webhook receiver.
 * Configure this URL in your Paystack dashboard: https://your-domain.com/payments/webhook.php
 *
 * Listens for: charge.success, subscription.disable
 */

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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$rawBody   = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

$payment = new PaymentService();

if (!$payment->isValidWebhookSignature($signature, $rawBody)) {
    http_response_code(401);
    Database::logError('Webhook signature validation failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event) || empty($event['event'])) {
    http_response_code(400);
    exit;
}

// Always respond 200 immediately to Paystack
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'received']);

// Process the event asynchronously after response flush
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    handleWebhookEvent($event, $payment);
} catch (Throwable $exception) {
    Database::logError('Webhook processing error', [
        'event'     => $event['event'],
        'exception' => $exception->getMessage(),
    ]);
}

function handleWebhookEvent(array $event, PaymentService $payment): void
{
    $eventName = (string) $event['event'];
    $data      = $event['data'] ?? [];

    switch ($eventName) {
        case 'charge.success':
            processChargeSuccess($data, $payment);
            break;

        case 'subscription.disable':
            processSubscriptionDisable($data);
            break;

        default:
            // Intentionally unhandled events — not an error
            break;
    }
}

function processChargeSuccess(array $data, PaymentService $payment): void
{
    $reference = (string) ($data['reference'] ?? '');
    if ($reference === '') {
        return;
    }

    // Guard against duplicate processing
    $db = Database::connection();
    $existing = $db->prepare('SELECT id FROM payments WHERE provider_reference = :ref LIMIT 1');
    $existing->execute(['ref' => $reference]);
    if ($existing->fetch()) {
        return; // Already recorded
    }

    $metadata   = $data['metadata'] ?? [];
    $tenantId   = (int) ($metadata['tenant_id'] ?? 0);
    $planId     = (int) ($metadata['plan_id']   ?? 0);
    $amountNgn  = (float) ($data['amount'] ?? 0) / 100;
    $currency   = (string) ($data['currency'] ?? 'NGN');

    $subscriptionId = null;
    if ($tenantId > 0 && $planId > 0) {
        $subscriptionId = $payment->activateSubscription($tenantId, $planId);
    }

    if ($tenantId > 0) {
        $payment->recordPayment($tenantId, $subscriptionId, 'paystack', $reference, $amountNgn, $currency, 'paid');
    }
}

function processSubscriptionDisable(array $data): void
{
    $email = (string) ($data['customer']['email'] ?? '');
    if ($email === '') {
        return;
    }

    $db = Database::connection();

    // Find tenant via user email
    $stmt = $db->prepare(
        'SELECT tenant_id FROM users WHERE email = :email LIMIT 1'
    );
    $stmt->execute(['email' => strtolower($email)]);
    $row = $stmt->fetch();

    if (!$row || empty($row['tenant_id'])) {
        return;
    }

    $db->prepare(
        'UPDATE subscriptions SET status = "cancelled" WHERE tenant_id = :tid AND status IN ("trial","active")'
    )->execute(['tid' => (int) $row['tenant_id']]);
}
