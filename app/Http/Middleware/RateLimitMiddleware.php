<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): void
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/ratelimits';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        $tenant = $request->attribute('tenant', []);
        $tenantKey = is_array($tenant) ? (string) ($tenant['tenant_id'] ?? $tenant['tenant_slug'] ?? 'global') : 'global';
        $key = sha1($request->ipAddress() . '|' . $tenantKey . '|' . date('YmdHi'));
        $path = $storageDir . '/' . $key . '.limit';
        $current = file_exists($path) ? (int) file_get_contents($path) : 0;

        if ($current >= 120) {
            (new Response())->json([
                'status' => 'error',
                'message' => 'Too many requests. Please slow down.',
            ], 429);
            return;
        }

        file_put_contents($path, (string) ($current + 1), LOCK_EX);
        $next($request);
    }
}
