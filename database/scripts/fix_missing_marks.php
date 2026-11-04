<?php
require_once __DIR__ . '/config/db.php';
$db = Database::connection();

// Find students missing marks for any term 1, 2, 3
$stmt = $db->query('SELECT id FROM students');
$students = $stmt->fetchAll(PDO::FETCH_COLUMN);

$checkStmt = $db->prepare('SELECT COUNT(*) FROM student_marks WHERE student_id = :student_id AND term = :term');
$insertStmt = null;
if (Database::columnExists('student_marks', 'school_year')) {
    $insertStmt = $db->prepare('INSERT INTO student_marks (student_id, term, marks, school_year) VALUES (:student_id, :term, 40, :school_year)');
} else {
    $insertStmt = $db->prepare('INSERT INTO student_marks (student_id, term, marks) VALUES (:student_id, :term, 40)');
}

$fixedCount = 0;
foreach ($students as $studentId) {
    for ($term = 1; $term <= 3; $term++) {
        $checkStmt->execute(['student_id' => $studentId, 'term' => $term]);
        if ($checkStmt->fetchColumn() == 0) {
            $insertStmt->execute(['student_id' => $studentId, 'term' => $term]);
            $fixedCount++;
        }
    }
}

echo "Fixed missing marks for $fixedCount term records.\n";
