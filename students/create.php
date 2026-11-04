<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';

require_auth();
assert_csrf_token($_POST['csrf_token'] ?? null);

$controller = new StudentController();
$response = $controller->create($_POST, current_auth_user(), $_FILES);

json_response($response, http_response_code() ?: 200);
