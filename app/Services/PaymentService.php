<?php
declare(strict_types=1);

namespace App\Services;

use Database;
use PDO;

/**
 * PaymentService — Paystack gateway integration.
 *
 * Environment variables required:
 *   PAYSTACK_SECRET_KEY   – secret key from the Paystack dashboard
 *   PAYSTACK_PUBLIC_KEY   – public key from the Paystack dashboard
 *   SBMS_BASE_URL         – Full base URL for callback redirect
 */
final class PaymentService
{
    private const PAYSTACK_BASE = 'https://api.paystack.co';

    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = (string) (getenv('PAYSTACK_SECRET_KEY') ?: '');
    }

    // -------------------------------------------------------------------------
    // Transaction lifecycle
    // -------------------------------------------------------------------------

    /**
     * Initialise a Paystack transaction and return the authorisation_url.
     *
     * @param  int    $amountKobo  Amount in smallest currency unit (kobo for NGN)
     * @return array{status: bool, data: array}|null
     */
    public function initializeTransaction(
        string $email,
        int    $amountKobo,
        string $reference,
        array  $metadata = []
    ): ?array {
        $callbackUrl = rtrim((string) (getenv('SBMS_BASE_URL') ?: ''), '/') . '/payments/callback.php';

        return $this->post('/transaction/initialize', [
            'email'        => $email,
            'amount'       => $amountKobo,
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'metadata'     => $metadata,
        ]);
    }

    /**
     * Verify a completed Paystack transaction by reference.
     *
     * @return array{status: bool, data: array}|null
     */
    public function verifyTransaction(string $reference): ?array
    {
        return $this->get('/transaction/verify/' . urlencode($reference));
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    /**
     * Validate the Paystack HMAC-SHA512 webhook signature.
     */
    public function isValidWebhookSignature(string $paystackSignature, string $rawBody): bool
    {
        if ($this->secretKey === '') {
            return false;
        }
        $computed = hash_hmac('sha512', $rawBody, $this->secretKey);
        return hash_equals($computed, strtolower($paystackSignature));
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Write a payment record to the payments table.
     */
    public function recordPayment(
        int     $tenantId,
        ?int    $subscriptionId,
        string  $provider,
        string  $reference,
        float   $amount,
        string  $currency,
        string  $status
    ): int {
        $db     = Database::connection();
        $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s') : null;

        $stmt = $db->prepare(
            'INSERT INTO payments
                 (tenant_id, subscription_id, provider, provider_reference,
                  amount, currency, status, paid_at, created_at)
             VALUES
                 (:tenant_id, :subscription_id, :provider, :reference,
                  :amount, :currency, :status, :paid_at, NOW())'
        );
        $stmt->bindValue(':tenant_id',       $tenantId,       PDO::PARAM_INT);
        $stmt->bindValue(':subscription_id', $subscriptionId, $subscriptionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':provider',        $provider,       PDO::PARAM_STR);
        $stmt->bindValue(':reference',       $reference,      PDO::PARAM_STR);
        $stmt->bindValue(':amount',          $amount);
        $stmt->bindValue(':currency',        $currency,       PDO::PARAM_STR);
        $stmt->bindValue(':status',          $status,         PDO::PARAM_STR);
        $stmt->bindValue(':paid_at',         $paidAt,         $paidAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return (int) $db->lastInsertId();
    }

    /**
     * Activate a subscription for a tenant, expiring any existing ones.
     *
     * @return int New subscription ID
     */
    public function activateSubscription(int $tenantId, int $planId, int $durationDays = 30): int
    {
        $db       = Database::connection();
        $startsAt = date('Y-m-d H:i:s');
        $endsAt   = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));

        // Expire any currently active subscription
        $db->prepare(
            'UPDATE subscriptions
             SET status = "expired"
             WHERE tenant_id = :tid AND status IN ("trial","active")'
        )->execute(['tid' => $tenantId]);

        $stmt = $db->prepare(
            'INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, ends_at, created_at)
             VALUES (:tenant_id, :plan_id, "active", :starts_at, :ends_at, NOW())'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'plan_id'   => $planId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
        ]);

        $subscriptionId = (int) $db->lastInsertId();

        // Update tenant status
        $db->prepare('UPDATE tenants SET status = "active" WHERE id = :id')
           ->execute(['id' => $tenantId]);

        return $subscriptionId;
    }

    /**
     * Return all active plans.
     */
    public function getPlans(): array
    {
        return Database::connection()
            ->query('SELECT id, name, code, price, billing_cycle, max_students, features FROM plans WHERE is_active = 1 ORDER BY price ASC')
            ->fetchAll();
    }

    /**
     * Generate a unique payment reference.
     */
    public function generateReference(int $tenantId): string
    {
        return sprintf('SBMS-%d-%s-%s', $tenantId, date('Ymd'), bin2hex(random_bytes(6)));
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function post(string $endpoint, array $payload): ?array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    private function get(string $endpoint): ?array
    {
        return $this->request('GET', $endpoint, []);
    }

    private function request(string $method, string $endpoint, array $payload): ?array
    {
        $ch = curl_init(self::PAYSTACK_BASE . $endpoint);
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            Database::logError('Paystack API request failed', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'error'     => $error,
            ]);
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
