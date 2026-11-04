<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

final class TenantContextService
{
    public function resolve(Request $request): ?array
    {
        $tenantId = $request->header('X-Tenant-Id');
        if (is_string($tenantId) && ctype_digit($tenantId)) {
            return ['tenant_id' => (int) $tenantId, 'source' => 'header'];
        }

        $host = (string) ($request->header('Host') ?? '');
        $host = explode(':', $host)[0];
        $parts = array_values(array_filter(explode('.', $host)));

        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'localhost'], true)) {
            return ['tenant_slug' => $parts[0], 'source' => 'subdomain'];
        }

        return null;
    }
}
