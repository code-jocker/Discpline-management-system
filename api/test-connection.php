<?php
/**
 * API Database Connection Test
 *
 * Visit this file after deployment to verify the backend can connect to the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config/Database.php';

try {
    $db = Database::connection();
    $result = $db->query('SELECT 1 AS status')->fetch();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'message' => 'Database connection successful.',
        'database' => getenv('DB_NAME') ?: getenv('SBMS_DB_NAME') ?: 'unknown',
        'host' => getenv('DB_HOST') ?: getenv('SBMS_DB_HOST') ?: 'unknown',
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed.',
        'details' => getenv('APP_DEBUG') === 'true' ? $e->getMessage() : 'Check server logs and database credentials.',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
