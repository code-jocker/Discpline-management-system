<?php
declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/Notification.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$pdo = Database::connection();
$parentNotifications = new \App\Services\ParentNotificationService($pdo);

$router = new Router();
$request = Request::capture();

require_once dirname(__DIR__) . '/routes/features.php';

return [
    'router' => $router,
    'request' => $request,
];
