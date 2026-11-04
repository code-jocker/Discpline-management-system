<?php
declare(strict_types=1);

/**
 * discipline/assess-risk.php
 *
 * Trigger an AI risk assessment for one or all students.
 *
 * POST  student_id=<int>   – assess a single student
 * POST  (no student_id)    – assess ALL students (admin only)
 *
 * Requires: auth, permission: assess_risk
 */

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\RiskEngineService;

require_auth();
require_permission('assess_risk');

$db         = Database::connection();
$service    = new RiskEngineService($db);
$authUser   = current_auth_user();
$studentId  = isset($_POST['student_id']) && ctype_digit((string) $_POST['student_id'])
    ? (int) $_POST['student_id']
    : null;

try {
    if ($studentId !== null) {
        $result = $service->assessStudent($studentId);

        if (!$result) {
            json_response(['status' => 'error', 'message' => 'Student not found or has no discipline data.'], 404);
        }

        json_response([
            'status'  => 'success',
            'message' => 'Risk assessment completed.',
            'data'    => $result,
        ]);
    }

    // Bulk assessment — admin / headteacher only
    $results = $service->assessAllStudents();
    json_response([
        'status'  => 'success',
        'message' => count($results) . ' students assessed.',
        'data'    => ['assessed' => count($results)],
    ]);
} catch (Throwable $exception) {
    Database::logError('Risk assessment endpoint failed', ['exception' => $exception->getMessage()]);
    json_response(['status' => 'error', 'message' => 'Risk assessment could not be completed.'], 500);
}
