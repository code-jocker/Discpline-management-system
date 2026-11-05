<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';

require_auth();

$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($studentId <= 0) {
    json_response(['status' => 'error', 'message' => 'A valid student is required.'], 422);
}

$controller = new StudentController();
json_response($controller->detail((int) $studentId), http_response_code() ?: 200);
