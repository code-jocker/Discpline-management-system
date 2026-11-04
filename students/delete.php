<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/controllers/StudentController.php';
require_once dirname(__DIR__) . '/models/Settings.php';

require_auth();
assert_csrf_token($_POST['csrf_token'] ?? null);

$controller = new StudentController();

if (($action = $_POST['action'] ?? '') === 'delete_all') {
    try {
        $db = Database::connection();
        $statement = $db->query('SELECT id FROM students');
        $studentIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        if ($studentIds === []) {
            json_response([
                'status' => 'success',
                'message' => 'No students found to delete.',
            ], 200);
            exit;
        }

        // Require explicit confirmation text to prevent accidental bulk deletions
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        if ($confirm !== 'DELETE ALL') {
            json_response([
                'status' => 'error',
                'message' => 'Bulk delete requires explicit confirmation. Please POST confirm_text=DELETE ALL.',
            ], 400);
            exit;
        }

        $deletedCount = 0;
        foreach ($studentIds as $studentId) {
            $result = $controller->delete(['id' => $studentId], current_auth_user());
            if (($result['status'] ?? '') === 'success') {
                $deletedCount++;
            }
        }

        // Record bulk delete timestamp in settings and write a short audit log
        try {
            $db = Database::connection();
            $settingsModel = new \Settings($db);
            $timestamp = (new DateTime())->format(DateTime::ATOM);
            $settingsModel->update(['last_bulk_delete' => $timestamp]);

            $logDir = dirname(__DIR__) . '/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/bulk_delete.log';
            $entry = sprintf("%s - admin_id=%d performed bulk delete, removed %d students\n", $timestamp, (int)(current_auth_user()['id'] ?? 0), $deletedCount);
            file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Non-fatal: continue to return success to caller
        }

        json_response([
            'status' => 'success',
            'message' => sprintf('Deleted %d student(s).', $deletedCount),
        ], 200);
        exit;
    } catch (Throwable $exception) {
        Database::logError('Bulk student deletion failed', ['exception' => $exception->getMessage()]);
        json_response([
            'status' => 'error',
            'message' => 'Failed to delete students.',
        ], 500);
        exit;
    }
}

$response = $controller->delete($_POST, current_auth_user());
json_response($response, http_response_code() ?: 200);
