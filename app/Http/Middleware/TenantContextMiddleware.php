<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Services\TenantContextService;
use App\Services\TenantService;
use App\Repositories\SubscriptionRepository;
use App\Repositories\TenantRepository;
use Database;

final class TenantContextMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): void
    {
        $tenant = (new TenantContextService())->resolve($request);
        if ($tenant !== null) {
            $request->setAttribute('tenant', $tenant);

            $service = new TenantService(
                new TenantRepository(Database::connection()),
                new SubscriptionRepository(Database::connection())
            );
            $context = $service->resolveContext($tenant);
            if ($context !== null) {
                $request->setAttribute('tenant_context', $context);
            }
        }

        $next($request);
    }
}
