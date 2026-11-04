<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/auth/csrf.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Student.php';

require_auth();

$authUser = current_auth_user();
if (empty($authUser) || !isset($authUser['id'])) {
    json_response(['status' => 'error', 'message' => 'Authentication required.'], 401);
}

$userModel = new User(Database::connection());
if (!$userModel->userHasPrivilegedRole((int) $authUser['id'])) {
    json_response(['status' => 'error', 'message' => 'Access denied.'], 403);
}

// Validate CSRF token
assert_csrf_token($_POST['csrf_token'] ?? null);

$studentId = isset($_POST['student_id']) ? max(0, (int) $_POST['student_id']) : 0;
if ($studentId <= 0) {
    json_response(['status' => 'error', 'message' => 'Invalid student id.'], 400);
}

try {
    $db = Database::connection();

    // Ensure student exists
    $stmt = $db->prepare('SELECT COUNT(*) FROM students WHERE id = :id');
    $stmt->execute(['id' => $studentId]);
    if ((int) $stmt->fetchColumn() === 0) {
        json_response(['status' => 'error', 'message' => 'Student not found.'], 404);
    }

    // Tables to clear offense-related records from (only if they exist)
    $tables = [
        'deductions_history',
        'behavior_incidents',
        'student_risk_profiles',
        'discipline_marks',
        'annual_discipline_reports'
    ];

    $db->beginTransaction();
    foreach ($tables as $table) {
        $check = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($check && $check->rowCount() > 0) {
            $del = $db->prepare("DELETE FROM {$table} WHERE student_id = :student_id");
            $del->execute(['student_id' => $studentId]);
        }
    }

    // Optionally, reset marks to full for this student if student_marks table is present
    $checkMarks = $db->query("SHOW TABLES LIKE 'student_marks'");
    if ($checkMarks && $checkMarks->rowCount() > 0) {
        // Set marks to 40 (full) where term entries exist
        $reset = $db->prepare('UPDATE student_marks SET marks = 40 WHERE student_id = :student_id');
        $reset->execute(['student_id' => $studentId]);
    }

    $db->commit();

    json_response(['status' => 'success', 'message' => 'Student offenses cleared.']);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    Database::logError('Failed to clear student offenses', ['error' => $e->getMessage()]);
    json_response(['status' => 'error', 'message' => 'Server error while clearing offenses.'], 500);
}
