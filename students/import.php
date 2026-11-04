<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';

require_auth();

function student_import_column_index(string $column): int
{
    $column = strtoupper(trim($column));
    $index = 0;
    $length = strlen($column);

    for ($position = 0; $position < $length; $position++) {
        $character = ord($column[$position]);
        if ($character < 65 || $character > 90) {
            break;
        }

        $index = ($index * 26) + ($character - 64);
    }

    return max(0, $index - 1);
}

function student_import_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $cellType = (string) ($cell['t'] ?? '');
    $value = (string) ($cell->v ?? '');

    if ($cellType === 's') {
        $sharedIndex = ctype_digit($value) ? (int) $value : -1;
        return $sharedStrings[$sharedIndex] ?? '';
    }

    if ($cellType === 'inlineStr') {
        return trim((string) ($cell->is->t ?? ''));
    }

    return trim($value);
}

function student_import_normalize_header(string $value): string
{
    $normalized = strtolower(trim((string) $value));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function student_import_parse_delimited_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $rows = [];
    $delimiter = "\t";

    if (!empty($lines)) {
        $firstLine = $lines[0];
        if (str_contains($firstLine, "\t")) {
            $delimiter = "\t";
        } elseif (str_contains($firstLine, ';')) {
            $delimiter = ';';
        } elseif (str_contains($firstLine, ',')) {
            $delimiter = ',';
        }
    }

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }

        $rows[] = str_getcsv($line, $delimiter);
    }

    return $rows;
}

function student_import_delete_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            student_import_delete_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function student_import_prepare_xlsx_directory(string $filePath): string
{
    $workingDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sbms_xlsx_' . bin2hex(random_bytes(8));
    if (!mkdir($workingDirectory, 0775, true) && !is_dir($workingDirectory)) {
        throw new RuntimeException('Unable to prepare a temporary workspace for the Excel file.');
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            student_import_delete_directory($workingDirectory);
            throw new RuntimeException('Unable to open the uploaded Excel file.');
        }

        if (!$zip->extractTo($workingDirectory)) {
            $zip->close();
            student_import_delete_directory($workingDirectory);
            throw new RuntimeException('Unable to extract the uploaded Excel file.');
        }

        $zip->close();
        return $workingDirectory;
    }

    if (!function_exists('shell_exec')) {
        student_import_delete_directory($workingDirectory);
        throw new RuntimeException('Spreadsheet support is unavailable on this server.');
    }

    $escapedSource = str_replace("'", "''", $filePath);
    $escapedTarget = str_replace("'", "''", $workingDirectory);
    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Expand-Archive -LiteralPath ' . "'{$escapedSource}'" . ' -DestinationPath ' . "'{$escapedTarget}'" . ' -Force"';
    $output = shell_exec($command . ' 2>&1');

    if (!is_dir($workingDirectory . DIRECTORY_SEPARATOR . 'xl')) {
        student_import_delete_directory($workingDirectory);
        throw new RuntimeException('Unable to extract the uploaded Excel file. ' . trim((string) $output));
    }

    return $workingDirectory;
}

function student_import_parse_xlsx(string $filePath): array
{
    $workingDirectory = student_import_prepare_xlsx_directory($filePath);

    $sharedStrings = [];
    $sharedStringsPath = $workingDirectory . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'sharedStrings.xml';
    if (is_file($sharedStringsPath)) {
        $sharedXml = simplexml_load_file($sharedStringsPath);
        if ($sharedXml !== false && isset($sharedXml->si)) {
            foreach ($sharedXml->si as $sharedItem) {
                $sharedStrings[] = trim((string) $sharedItem->t);
            }
        }
    }

    $sheetXmlPath = $workingDirectory . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . 'sheet1.xml';
    if (!is_file($sheetXmlPath)) {
        student_import_delete_directory($workingDirectory);
        throw new RuntimeException('The Excel file does not contain a readable first worksheet.');
    }

    $worksheet = simplexml_load_file($sheetXmlPath);
    student_import_delete_directory($workingDirectory);

    if ($worksheet === false || !isset($worksheet->sheetData->row)) {
        throw new RuntimeException('Unable to read worksheet data from the Excel file.');
    }

    $rows = [];
    foreach ($worksheet->sheetData->row as $row) {
        $rowValues = [];
        foreach ($row->c as $cell) {
            $reference = (string) ($cell['r'] ?? '');
            $columnIndex = $reference !== '' ? student_import_column_index(preg_replace('/\d+/', '', $reference)) : count($rowValues);
            $rowValues[$columnIndex] = student_import_cell_value($cell, $sharedStrings);
        }

        ksort($rowValues);
        $rows[] = array_values($rowValues);
    }

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

assert_csrf_token($_POST['csrf_token'] ?? null);

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $pasteData = trim((string) ($_POST['paste_data'] ?? ''));
    if ($pasteData === '') {
        json_response(['status' => 'error', 'message' => 'Please upload a CSV or Excel file, or paste spreadsheet data from Excel.'], 400);
    }
}

$file = $_FILES['csv_file']['tmp_name'] ?? '';
$handle = null;

$pasteData = trim((string) ($_POST['paste_data'] ?? ''));

$rows = [];
if ($pasteData !== '') {
    $rows = student_import_parse_delimited_text($pasteData);
} elseif ($file !== '') {
    $originalName = strtolower((string) ($_FILES['csv_file']['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension === 'xlsx') {
        $rows = student_import_parse_xlsx($file);
    } else {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            json_response(['status' => 'error', 'message' => 'Unable to read the uploaded file.'], 500);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            json_response(['status' => 'error', 'message' => 'Invalid CSV format. Missing required full_name column.'], 400);
        }

        $normalizedHeader = array_map(
            static fn (string $value): string => student_import_normalize_header($value),
            $header
        );
        $headerAliases = [
            'full_name' => 'full_name',
            'student_name' => 'full_name',
            'student_full_name' => 'full_name',
            'name' => 'full_name',
            'class_id' => 'class_id',
            'student_class_id' => 'class_id',
            'category_id' => 'category_id',
            'student_category_id' => 'category_id',
            'parent_contact' => 'parent_contact',
            'parent_phone' => 'parent_contact',
            'phone' => 'parent_contact',
        ];
        foreach ($normalizedHeader as &$headerName) {
            if (isset($headerAliases[$headerName])) {
                $headerName = $headerAliases[$headerName];
            }
        }
        unset($headerName);

        if (!in_array('full_name', $normalizedHeader, true)) {
            fclose($handle);
            json_response(['status' => 'error', 'message' => 'Invalid CSV format. Missing required full_name column.'], 400);
        }

        $rows[] = $header;
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }

        fclose($handle);
    }
} else {
    json_response(['status' => 'error', 'message' => 'Please upload a CSV or Excel file, or paste spreadsheet data from Excel.'], 400);
}

if (empty($rows)) {
    json_response(['status' => 'error', 'message' => 'The uploaded spreadsheet is empty.'], 400);
}

$firstRow = $rows[0] ?? [];
$normalizedFirstRow = array_map(static fn ($value) => student_import_normalize_header((string) $value), $firstRow);
$knownHeaders = ['full_name', 'class_id', 'category_id', 'parent_contact'];
$hasExplicitHeader = count(array_intersect($normalizedFirstRow, $knownHeaders)) > 0;

if (!$hasExplicitHeader && count($firstRow) === 1) {
    array_unshift($rows, ['full_name']);
}

$header = array_map(static fn ($value) => student_import_normalize_header((string) $value), array_shift($rows));
$headerAliases = [
    'full_name' => 'full_name',
    'student_name' => 'full_name',
    'student_full_name' => 'full_name',
    'name' => 'full_name',
    'class_id' => 'class_id',
    'student_class_id' => 'class_id',
    'category_id' => 'category_id',
    'student_category_id' => 'category_id',
    'parent_contact' => 'parent_contact',
    'parent_phone' => 'parent_contact',
    'phone' => 'parent_contact',
];

foreach ($header as &$headerName) {
    if (isset($headerAliases[$headerName])) {
        $headerName = $headerAliases[$headerName];
    }
}
unset($headerName);

if (!in_array('full_name', $header, true)) {
    json_response(['status' => 'error', 'message' => 'Invalid spreadsheet format. Missing required full_name column.'], 400);
}

$controller = new StudentController();
$authUser = current_auth_user();

if (!$controller->canManageStudents($authUser)) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
}

$defaultClassId = trim((string) ($_POST['class_id'] ?? ''));
$defaultCategoryId = trim((string) ($_POST['category_id'] ?? ''));

if ($defaultClassId === '' || !ctype_digit($defaultClassId)) {
    json_response(['status' => 'error', 'message' => 'Please choose a class for the import.'], 400);
}

if ($defaultCategoryId === '' || !ctype_digit($defaultCategoryId)) {
    json_response(['status' => 'error', 'message' => 'Please choose a category for the import.'], 400);
}

$successCount = 0;
$errors = [];
$row = 2;

// Find index of columns
$nameIdx = array_search('full_name', $header, true);
$classIdx = array_search('class_id', $header, true);
$catIdx = array_search('category_id', $header, true);
$contactIdx = array_search('parent_contact', $header, true);

foreach ($rows as $data) {
    // skip empty rows
    if (empty(array_filter($data))) {
        $row++;
        continue;
    }

    $payload = [
        'full_name' => $data[$nameIdx] ?? '',
        'class_id' => $classIdx !== false ? ($data[$classIdx] ?? '') : $defaultClassId,
        'category_id' => $catIdx !== false ? ($data[$catIdx] ?? '') : $defaultCategoryId,
    ];

    if ($contactIdx !== false) {
        $payload['parent_contact'] = $data[$contactIdx] ?? '';
    }

    $response = $controller->create($payload, $authUser);
    if ($response['status'] === 'success') {
        $successCount++;
    } else {
        $errors[] = "Row {$row}: " . $response['message'];
    }
    $row++;
}

$message = "Successfully imported {$successCount} students.";
if (!empty($errors)) {
    $message .= " Encountered " . count($errors) . " errors.";
}

json_response([
    'status' => empty($errors) ? 'success' : 'partial',
    'message' => $message,
    'success_count' => $successCount,
    'errors' => array_slice($errors, 0, 10) // return max 10 errors to avoid huge payload
], 200);
