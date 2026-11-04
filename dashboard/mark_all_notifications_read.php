<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/Notification.php';

require_auth();
$user = current_auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

assert_csrf_token($_POST['csrf_token'] ?? null);

try {
    $db = Database::connection();
    $notificationModel = new Notification($db);
    
    if ($notificationModel->markAllAsRead((int)$user['id'])) {
        json_response([
            'status' => 'success', 
            'message' => 'All notifications marked as read.',
            'unread_count' => 0
        ]);
    } else {
        json_response(['status' => 'error', 'message' => 'Failed to update notifications.'], 500);
    }
} catch (Throwable $exception) {
    Database::logError('Mark all notifications read failed', ['error' => $exception->getMessage()]);
    json_response(['status' => 'error', 'message' => 'An internal error occurred.'], 500);
}