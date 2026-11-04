<?php
declare(strict_types=1);

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

use App\Services\ReportService;

require_auth();
require_permission('generate_reports');

$format = $_GET['format'] ?? 'csv';
$service = new ReportService(Database::connection());

if ($format === 'pdf') {
    echo $service->generateHighRiskReportHtml();
    exit;
}

$csv = $service->generateHighRiskReportCsv();

$filename = "high_risk_students_report_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $csv;
