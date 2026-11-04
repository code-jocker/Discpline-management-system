<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/Notification.php';

require_auth();
$user = current_auth_user();

try {
    $db = Database::connection();
    $notificationModel = new Notification($db);
    $scope = $notificationModel->describeVisibilityScope((int) $user['id']);
    
    // Fetch unread alerts and group them by date
    $notifications = $notificationModel->fetchUnread((int)$user['id'], 5);
    $grouped = $notificationModel->formatGrouped($notifications);
    $unreadCount = $notificationModel->countUnread((int)$user['id']);

    json_response([
        'status' => 'success',
        'data' => $grouped,
        'unread_count' => $unreadCount,
        'scope' => $scope
    ]);
} catch (Throwable $exception) {
    Database::logError('Dashboard notifications API failed', ['error' => $exception->getMessage()]);
    json_response([
        'status' => 'error',
        'message' => 'Unable to retrieve notifications.'
    ], 500);
}
