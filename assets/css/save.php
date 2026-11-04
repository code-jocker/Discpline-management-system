<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/database/SettingsController.php';

require_auth();
require_role_ids([1]);
assert_csrf_token($_POST['csrf_token'] ?? null);

$controller = new SettingsController();
$response = $controller->save($_POST);

json_response($response);