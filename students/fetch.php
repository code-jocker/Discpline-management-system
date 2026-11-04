<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';

require_auth();

$controller = new StudentController();
$response = $controller->fetch($_GET);

json_response($response, http_response_code() ?: 200);
