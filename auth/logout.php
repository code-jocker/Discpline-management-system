<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/config/db.php'; // For Database::connection() in AuthController
require_once dirname(__DIR__) . '/auth/AuthController.php';

start_secure_session();
$authController = new AuthController();
$authController->logout();

if (is_ajax_request()) {
    json_response(['status' => 'success', 'message' => 'You have been logged out.']);
}

header('Location: ' . app_base_url() . '/auth/login.php');
exit;
