<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\JwtService;
use Database;

final class JwtAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            (new Response())->json([
                'status' => 'error',
                'message' => 'Authentication token is required.',
            ], 401);
            return;
        }

        $claims = (new JwtService())->decode($token);
        if (!$claims || empty($claims['sub'])) {
            (new Response())->json([
                'status' => 'error',
                'message' => 'Authentication token is invalid or expired.',
            ], 401);
            return;
        }

        $user = (new UserRepository(Database::connection()))->findById((int) $claims['sub']);
        if (!$user) {
            (new Response())->json([
                'status' => 'error',
                'message' => 'Authenticated user no longer exists.',
            ], 401);
            return;
        }

        $request->setAttribute('auth_user', $user);
        $request->setAttribute('jwt_claims', $claims);
        $next($request);
    }
}
