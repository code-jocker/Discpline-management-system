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

$notificationId = (int)($_POST['id'] ?? 0);
if ($notificationId <= 0) {
    json_response(['status' => 'error', 'message' => 'Invalid notification ID.'], 422);
}

try {
    $db = Database::connection();
    $notificationModel = new Notification($db);
    
    if ($notificationModel->markAsRead($notificationId, (int)$user['id'])) {
        json_response([
            'status' => 'success', 
            'message' => 'Notification marked as read.',
            'unread_count' => $notificationModel->countUnread((int)$user['id'])
        ]);
    } else {
        json_response(['status' => 'error', 'message' => 'Failed to update notification.'], 500);
    }
} catch (Throwable $exception) {
    Database::logError('Mark notification read failed', ['error' => $exception->getMessage()]);
    json_response(['status' => 'error', 'message' => 'An internal error occurred.'], 500);
}