<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/controllers/DisciplineController.php';

require_auth();

$controller = new DisciplineController();
$response = $controller->unreadNotifications(current_auth_user());

json_response($response, http_response_code() ?: 200);
