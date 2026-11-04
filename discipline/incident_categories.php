<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/IncidentCategory.php';

require_auth();

$db = Database::connection();
$model = new IncidentCategory($db);

try {
    $model->ensureTable();
    $model->seed();

    json_response([
        'status' => 'success',
        'data' => ['items' => $model->all()],
    ], 200);
} catch (Throwable $exception) {
    error_log('[incident_categories] ' . $exception->getMessage());
    json_response(['status' => 'error', 'message' => 'Unable to load incident categories.'], 500);
}
