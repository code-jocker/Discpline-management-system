<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/ClassController.php';

require_auth();
assert_csrf_token($_POST['csrf_token'] ?? null);

$controller = new ClassController();
$response = $controller->assignTeacher($_POST, current_auth_user());

json_response($response, http_response_code() ?: 200);
