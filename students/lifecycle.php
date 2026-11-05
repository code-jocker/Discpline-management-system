<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';

require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Use POST for lifecycle actions.'], 405);
}

assert_csrf_token($_POST['csrf_token'] ?? null);

$controller = new StudentController();
$response = $controller->lifecycle($_POST, current_auth_user());

json_response($response, http_response_code() ?: 200);
