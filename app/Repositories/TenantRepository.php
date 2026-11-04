<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TenantRepository
{
    private PDO $db;
    private ?bool $tenantTableExists = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function resolveByContext(?int $tenantId, ?string $slug, ?string $domain): ?array
    {
        if (!$this->hasTenantTable()) {
            return null;
        }

        if ($tenantId !== null) {
            $statement = $this->db->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $tenantId]);
            $tenant = $statement->fetch();
            if ($tenant) {
                return $tenant;
            }
        }

        if ($slug !== null && $slug !== '') {
            $statement = $this->db->prepare('SELECT * FROM tenants WHERE slug = :slug LIMIT 1');
            $statement->execute(['slug' => strtolower($slug)]);
            $tenant = $statement->fetch();
            if ($tenant) {
                return $tenant;
            }
        }

        if ($domain !== null && $domain !== '') {
            $statement = $this->db->prepare('SELECT * FROM tenants WHERE domain = :domain LIMIT 1');
            $statement->execute(['domain' => strtolower($domain)]);
            $tenant = $statement->fetch();
            if ($tenant) {
                return $tenant;
            }
        }

        return null;
    }

    public function hasTenantTable(): bool
    {
        if ($this->tenantTableExists !== null) {
            return $this->tenantTableExists;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => 'tenants']);

        $this->tenantTableExists = (int) $statement->fetchColumn() > 0;
        return $this->tenantTableExists;
    }
}
