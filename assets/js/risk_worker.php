<?php
declare(strict_types=1);

/**
 * SBMS Risk Assessment Background Worker
 * Run via CLI: php c:\xampp\htdocs\sbms\assets\js\risk_worker.php
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/Services/AiRiskService.php';
require_once dirname(__DIR__, 2) . '/app/Services/RiskEngineService.php';

use App\Services\RiskEngineService;

echo "[ " . date('Y-m-d H:i:s') . " ] Starting Risk Assessment Sync..." . PHP_EOL;

try {
    $db = Database::connection();
    $riskEngine = new RiskEngineService($db);

    $results = $riskEngine->assessAllStudents();

    echo "[ " . date('Y-m-d H:i:s') . " ] Successfully updated " . count($results) . " profiles." . PHP_EOL;

} catch (Throwable $e) {
    error_log("Risk Worker Failed: " . $e->getMessage());
    echo "CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
}