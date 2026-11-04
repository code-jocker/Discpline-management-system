<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

final class SubscriptionActiveMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): void
    {
        $tenantContext = $request->attribute('tenant_context');

        // Local single-tenant installs can continue even before SaaS tables are provisioned.
        if ($tenantContext === null) {
            $next($request);
            return;
        }

        $subscription = $tenantContext['subscription'] ?? null;
        if ($subscription === null) {
            (new Response())->json([
                'status' => 'error',
                'message' => 'Tenant subscription is inactive or missing.',
            ], 402);
            return;
        }

        $next($request);
    }
}
