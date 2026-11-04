<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class DisciplineRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getStudentTermForUpdate(int $studentId, int $term): ?array
    {
        $statement = $this->db->prepare(
            'SELECT s.id AS student_id, s.student_code, s.full_name, s.class_id,
                    sm.term, sm.marks AS current_marks
             FROM students s
             INNER JOIN student_marks sm ON sm.student_id = s.id
             WHERE s.id = :student_id AND sm.term = :term
             FOR UPDATE'
        );
        $statement->execute([
            'student_id' => $studentId,
            'term' => $term,
        ]);

        $row = $statement->fetch();
        return $row ?: null;
    }

    public function deductMarks(int $studentId, int $term, int $marks): void
    {
        $statement = $this->db->prepare(
            'UPDATE student_marks
             SET marks = LEAST(40, GREATEST(0, marks - :marks))
             WHERE student_id = :student_id AND term = :term'
        );
        $statement->execute([
            'marks' => $marks,
            'student_id' => $studentId,
            'term' => $term,
        ]);
    }

    public function setMarks(int $studentId, int $term, int $marks): void
    {
        $normalizedMarks = max(0, min(40, $marks));
        $statement = $this->db->prepare(
            'UPDATE student_marks
             SET marks = :marks
             WHERE student_id = :student_id AND term = :term'
        );
        $statement->execute([
            'marks' => $normalizedMarks,
            'student_id' => $studentId,
            'term' => $term,
        ]);
    }

    public function getStudentIdsByClass(int $classId): array
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM students
             WHERE class_id = :class_id
             ORDER BY full_name ASC'
        );
        $statement->execute([
            'class_id' => $classId,
        ]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function createHistory(int $studentId, int $term, int $marks, string $reason, int $performedBy): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO deductions_history (student_id, term, marks_deducted, reason, performed_by, `timestamp`)
             VALUES (:student_id, :term, :marks_deducted, :reason, :performed_by, NOW())'
        );
        $statement->execute([
            'student_id' => $studentId,
            'term' => $term,
            'marks_deducted' => $marks,
            'reason' => $reason,
            'performed_by' => $performedBy,
        ]);
    }

    public function inTransaction(callable $callback): mixed
    {
        $startedTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $result = $callback();
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
