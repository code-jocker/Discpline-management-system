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

$term = isset($_GET['term']) && ctype_digit((string) $_GET['term']) ? (int) $_GET['term'] : null;
$classId = isset($_GET['class_id']) && ctype_digit((string) $_GET['class_id']) ? (int) $_GET['class_id'] : null;

if ($term === null || $term < 1 || $term > 3) {
    http_response_code(400);
    exit('Missing or invalid term parameter.');
}

$format = $_GET['format'] ?? 'csv';
$service = new ReportService(Database::connection());

if ($format === 'pdf') {
    if ($classId !== null) {
        $html = $service->generateClassTermReportHtml($classId, $term);
        if ($html === null) {
            http_response_code(404);
            exit('Class not found.');
        }
        echo $html;
        exit;
    }

    echo $service->generateTermReportHtml($term);
    exit;
}

if ($classId !== null) {
    $csv = $service->generateClassTermReportCsv($classId, $term);
    if ($csv === null) {
        http_response_code(404);
        exit('Class not found.');
    }
    $filename = "class_{$classId}_term_{$term}_report.csv";
} else {
    $csv = $service->generateTermReportCsv($term);
    $filename = "term_{$term}_report.csv";
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $csv;
