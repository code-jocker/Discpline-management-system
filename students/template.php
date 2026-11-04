<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_auth();

$filename = "student_import_template.csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fputcsv($output, ['full_name']);
fclose($output);
exit;
