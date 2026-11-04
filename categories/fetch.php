<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/controllers/CategoryController.php';

require_auth();

$controller = new CategoryController();
$response = $controller->fetch($_GET);

json_response($response, http_response_code() ?: 200);
