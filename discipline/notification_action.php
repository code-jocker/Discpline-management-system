<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/DisciplineController.php';

require_auth();
assert_csrf_token($_POST['csrf_token'] ?? null);

$action = $_POST['action'] ?? '';
$notificationId = (int) ($_POST['id'] ?? 0);
$user = current_auth_user();

if (!$user || ($notificationId <= 0 && !in_array($action, ['hide_all', 'delete_all'], true))) {
    json_response(['status' => 'error', 'message' => 'Invalid request.'], 400);
}

$db = Database::connection();
require_once dirname(__DIR__) . '/models/Notification.php';
$notification = new Notification($db);

$success = false;
$message = '';

if ($action === 'delete') {
    $success = $notification->delete($notificationId, (int) $user['id']);
    $message = 'Notification deleted.';
} elseif ($action === 'hide' || $action === 'read') {
    $success = $notification->markAsRead($notificationId, (int) $user['id']);
    $message = 'Notification hidden.';
} elseif ($action === 'hide_all') {
    $success = $notification->markAllAsRead((int) $user['id']);
    $message = 'All notifications hidden.';
} elseif ($action === 'delete_all') {
    $success = $notification->deleteAll((int) $user['id']);
    $message = 'All notifications deleted.';
} else {
    json_response(['status' => 'error', 'message' => 'Invalid action.'], 400);
}

if ($success) {
    json_response(['status' => 'success', 'message' => $message], 200);
} else {
    json_response(['status' => 'error', 'message' => 'Unable to process action.'], 500);
}
