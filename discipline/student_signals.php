<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/check.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/IncidentCategory.php';
require_once dirname(__DIR__) . '/models/SchoolYear.php';

require_auth();

$studentId = (int) ($_GET['student_id'] ?? 0);
$term = SchoolYear::normalizeTerm((int) ($_GET['term'] ?? 1));

if ($studentId <= 0) {
    json_response(['status' => 'error', 'message' => 'A valid student is required.'], 422);
}

$db = Database::connection();

try {
    $statement = $db->prepare(
        'SELECT s.id, s.full_name, s.student_code, c.name AS class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE s.id = :id'
    );
    $statement->execute(['id' => $studentId]);
    $student = $statement->fetch();

    if (!$student) {
        json_response(['status' => 'error', 'message' => 'Student not found.'], 404);
    }

    $marksStatement = $db->prepare(
        'SELECT marks FROM student_marks WHERE student_id = :student_id AND term = :term'
    );
    $marksStatement->execute(['student_id' => $studentId, 'term' => $term]);
    $currentMarks = $marksStatement->fetchColumn();

    $categories = new IncidentCategory($db);
    $categories->ensureTable();
    $signals = $categories->attendanceSignals($studentId, $term);

    json_response([
        'status' => 'success',
        'data' => [
            'student' => [
                'id' => (int) $student['id'],
                'full_name' => $student['full_name'],
                'student_code' => $student['student_code'],
                'class_name' => $student['class_name'] ?? '—',
            ],
            'term' => $term,
            'current_marks' => $currentMarks === false ? null : (int) $currentMarks,
            'signals' => $signals,
        ],
    ], 200);
} catch (Throwable $exception) {
    error_log('[student_signals] ' . $exception->getMessage());
    json_response(['status' => 'error', 'message' => 'Unable to load student signals.'], 500);
}
