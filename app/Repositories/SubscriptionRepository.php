<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SubscriptionRepository
{
    private PDO $db;
    private ?bool $subscriptionTableExists = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function activeForTenant(int $tenantId): ?array
    {
        if (!$this->hasSubscriptionTable()) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT s.*, p.name AS plan_name, p.code AS plan_code
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.tenant_id = :tenant_id
               AND s.status IN ("trial", "active")
               AND (s.ends_at IS NULL OR s.ends_at >= NOW())
             ORDER BY s.id DESC
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $subscription = $statement->fetch();

        return $subscription ?: null;
    }

    public function hasSubscriptionTable(): bool
    {
        if ($this->subscriptionTableExists !== null) {
            return $this->subscriptionTableExists;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => 'subscriptions']);

        $this->subscriptionTableExists = (int) $statement->fetchColumn() > 0;
        return $this->subscriptionTableExists;
    }
}
