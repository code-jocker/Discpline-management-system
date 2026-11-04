<?php
declare(strict_types=1);

/**
 * reports/student.php
 *
 * Generates a printer-friendly HTML student report.
 * Open in browser and use Ctrl+P / Cmd+P to save as PDF.
 *
 * GET ?student_id=<int>
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

use App\Services\ReportService;

require_auth();
require_permission('generate_reports');

$studentId = isset($_GET['student_id']) && ctype_digit((string) $_GET['student_id'])
    ? (int) $_GET['student_id']
    : null;

if ($studentId === null) {
    http_response_code(400);
    exit('Missing or invalid student_id parameter.');
}

$service = new ReportService(Database::connection());
$html    = $service->generateStudentReport($studentId);

if ($html === null) {
    http_response_code(404);
    exit('Student not found.');
}

// Output as HTML — printable to PDF via browser
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
echo $html;
