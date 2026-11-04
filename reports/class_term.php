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

$classId = isset($_GET['class_id']) && ctype_digit((string) $_GET['class_id']) ? (int) $_GET['class_id'] : null;
$term = isset($_GET['term']) && ctype_digit((string) $_GET['term']) ? (int) $_GET['term'] : null;

if ($classId === null || $term === null) {
    http_response_code(400);
    exit('Missing or invalid parameters.');
}

$isClassTeacher = false;
$stmtCheck = Database::connection()->prepare('SELECT class_teacher_user_id FROM classes WHERE id = :id');
$stmtCheck->execute(['id' => $classId]);
if ((int) $stmtCheck->fetchColumn() === (int) current_user_id()) {
    $isClassTeacher = true;
}

if (!current_user_has_permission('generate_reports') && !$isClassTeacher) {
    http_response_code(403);
    exit('Access denied. You must be the class teacher or have reporting permissions.');
}

if ($term < 1 || $term > 3) {
    http_response_code(400);
    exit('Invalid term.');
}

$format = $_GET['format'] ?? 'csv';

$service = new ReportService(Database::connection());

if ($format === 'pdf') {
    $html = $service->generateClassTermReportHtml($classId, $term);
    if ($html === null) {
        http_response_code(404);
        exit('Class not found.');
    }
    echo $html;
    exit;
}

$csv = $service->generateClassTermReportCsv($classId, $term);

if ($csv === null) {
    http_response_code(404);
    exit('Class not found.');
}

$filename = "class_{$classId}_term_{$term}_report.csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $csv;
