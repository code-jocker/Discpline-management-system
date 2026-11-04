<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/controllers/DisciplineController.php';

require_auth();

$controller = new DisciplineController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	require_once dirname(__DIR__) . '/auth/csrf.php';
	assert_csrf_token($_POST['csrf_token'] ?? null);
	$response = $controller->deleteHistory($_POST, current_auth_user());
	json_response($response, http_response_code() ?: 200);
}

$response = $controller->history($_GET, current_auth_user());

json_response($response, http_response_code() ?: 200);
