<?php
declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthApiController;
use App\Http\Controllers\Api\V1\DashboardApiController;
use App\Http\Controllers\Api\V1\DisciplineApiController;
use App\Http\Controllers\Api\V1\StudentApiController;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SubscriptionActiveMiddleware;
use App\Http\Middleware\TenantContextMiddleware;

$router->group([
    'prefix' => '/mobile',
    'middleware' => [
        SecurityHeadersMiddleware::class,
        TenantContextMiddleware::class,
        RateLimitMiddleware::class,
    ],
], static function ($router): void {
    $router->post('/login', [AuthApiController::class, 'login']);

    $router->group([
        'middleware' => [JwtAuthMiddleware::class, SubscriptionActiveMiddleware::class],
    ], static function ($router): void {
        $router->get('/dashboard', [DashboardApiController::class, 'index']);
        $router->get('/students', [StudentApiController::class, 'index']);
        $router->post('/discipline/deduct', [DisciplineApiController::class, 'deduct']);
    });
});
