<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/auth/session.php';

require_auth();

try {
    $db = Database::connection();
    $settingsModel = new Settings($db);
    $last = $settingsModel->get('last_bulk_delete', null);

    json_response([
        'status' => 'success',
        'last_bulk_delete' => $last,
    ], 200);
} catch (Throwable $e) {
    json_response([
        'status' => 'error',
        'message' => 'Unable to retrieve last bulk delete timestamp.'
    ], 500);
}
