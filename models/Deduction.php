<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

final class Deduction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getStudentTermSnapshot(int $studentId, int $term, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT s.id AS student_id, s.student_code, s.full_name, s.class_id,
                       c.name AS class_name, cat.name AS category_name,
                       sm.term, sm.marks AS current_marks
                FROM students s
                LEFT JOIN classes c ON c.id = s.class_id
                LEFT JOIN categories cat ON cat.id = s.category_id
                LEFT JOIN student_marks sm ON sm.student_id = s.id AND sm.term = :term
                WHERE s.id = :student_id';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'student_id' => $studentId,
            'term' => $term,
        ]);

        $record = $statement->fetch();

        if (!$record || !array_key_exists('current_marks', $record) || $record['current_marks'] === null) {
            $checkStmt = $this->db->prepare('SELECT COUNT(*) FROM students WHERE id = :id');
            $checkStmt->execute(['id' => $studentId]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                $checkMarks = $this->db->prepare('SELECT COUNT(*) FROM student_marks WHERE student_id = :student_id AND term = :term');
                $checkMarks->execute(['student_id' => $studentId, 'term' => $term]);
                if ((int) $checkMarks->fetchColumn() === 0) {
                    if (\Database::columnExists('student_marks', 'school_year')) {
                        $insertStmt = $this->db->prepare('INSERT INTO student_marks (student_id, term, marks, school_year) VALUES (:student_id, :term, 40, :school_year)');
                        $insertStmt->execute(['student_id' => $studentId, 'term' => $term, 'school_year' => (new \Settings($this->db))->selectedSchoolYear()]);
                    } else {
                        $insertStmt = $this->db->prepare('INSERT INTO student_marks (student_id, term, marks) VALUES (:student_id, :term, 40)');
                        $insertStmt->execute(['student_id' => $studentId, 'term' => $term]);
                    }
                }

                $statement->execute([
                    'student_id' => $studentId,
                    'term' => $term,
                ]);
                $record = $statement->fetch();
            }
        }

        return ($record && $record['current_marks'] !== null) ? $record : null;
    }

    public function insertHistory(
        int $studentId,
        int $term,
        int $marksDeducted,
        string $reason,
        int $performedBy,
        ?int $evidenceFileId = null,
        ?string $incidentCategory = null
    ): int {
        if (Database::columnExists('deductions_history', 'incident_category')) {
            $statement = $this->db->prepare(
                'INSERT INTO deductions_history (student_id, term, marks_deducted, reason, performed_by, evidence_file_id, incident_category, `timestamp`)
                 VALUES (:student_id, :term, :marks_deducted, :reason, :performed_by, :evidence_file_id, :incident_category, NOW())'
            );
            $statement->execute([
                'student_id' => $studentId,
                'term' => $term,
                'marks_deducted' => $marksDeducted,
                'reason' => $reason,
                'performed_by' => $performedBy,
                'evidence_file_id' => $evidenceFileId,
                'incident_category' => $incidentCategory,
            ]);
        } else {
            $statement = $this->db->prepare(
                'INSERT INTO deductions_history (student_id, term, marks_deducted, reason, performed_by, evidence_file_id, `timestamp`)
                 VALUES (:student_id, :term, :marks_deducted, :reason, :performed_by, :evidence_file_id, NOW())'
            );
            $statement->execute([
                'student_id' => $studentId,
                'term' => $term,
                'marks_deducted' => $marksDeducted,
                'reason' => $reason,
                'performed_by' => $performedBy,
                'evidence_file_id' => $evidenceFileId,
            ]);
        }

        return (int) $this->db->lastInsertId();
    }

    public function applyMarksDeduction(int $studentId, int $term, int $marksToDeduct): void
    {
        if (\Database::columnExists('student_marks', 'school_year')) {
            $sql = 'UPDATE student_marks
                    SET marks = LEAST(40, GREATEST(0, marks - :marks_to_deduct))
                    WHERE student_id = :student_id AND term = :term AND school_year = :school_year';
            $statement = $this->db->prepare($sql);
            $statement->execute([
                'marks_to_deduct' => $marksToDeduct,
                'student_id' => $studentId,
                'term' => $term,
                'school_year' => (new \Settings($this->db))->selectedSchoolYear(),
            ]);
        } else {
            $statement = $this->db->prepare(
                'UPDATE student_marks
                 SET marks = LEAST(40, GREATEST(0, marks - :marks_to_deduct))
                 WHERE student_id = :student_id AND term = :term'
            );
            $statement->execute([
                'marks_to_deduct' => $marksToDeduct,
                'student_id' => $studentId,
                'term' => $term,
            ]);
        }
    }

    public function setMarks(int $studentId, int $term, int $marks): void
    {
        $normalizedMarks = max(0, min(40, $marks));
        if (\Database::columnExists('student_marks', 'school_year')) {
            $statement = $this->db->prepare(
                'UPDATE student_marks
                 SET marks = :marks
                 WHERE student_id = :student_id AND term = :term AND school_year = :school_year'
            );
            $statement->execute([
                'marks' => $normalizedMarks,
                'student_id' => $studentId,
                'term' => $term,
                'school_year' => (new \Settings($this->db))->selectedSchoolYear(),
            ]);
        } else {
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
    }

    public function fetchHistory(array $filters, int $limit, int $offset, string $sortBy): array
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['student_id'])) {
            $where[] = 'dh.student_id = :student_id';
            $bindings['student_id'] = (int) $filters['student_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 's.class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['term'])) {
            $where[] = 'dh.term = :term';
            $bindings['term'] = (int) $filters['term'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderBy = $sortBy === 'most_deducted'
            ? 'dh.marks_deducted DESC, dh.`timestamp` DESC'
            : 'dh.`timestamp` DESC';

        $letterSelect = Database::tableExists('student_letters')
            ? 'sl.id AS letter_id, sl.generated_at AS letter_generated_at, '
            : '';
        $letterJoin = Database::tableExists('student_letters')
            ? 'LEFT JOIN student_letters sl ON sl.student_id = dh.student_id AND sl.term = dh.term'
            : '';

        $incidentCategorySelect = Database::columnExists('deductions_history', 'incident_category')
            ? 'dh.incident_category, '
            : 'NULL AS incident_category, ';

        $sql = sprintf(
            'SELECT dh.id, dh.student_id, dh.term, dh.marks_deducted, dh.reason, %s dh.performed_by, dh.evidence_file_id, dh.`timestamp`,
                    s.student_code, s.full_name, s.class_id, c.name AS class_name,
                    performer.full_name AS performed_by_name,
                    %s sf.storage_path, sf.original_name, sf.mime_type
             FROM deductions_history dh
             INNER JOIN students s ON s.id = dh.student_id
             INNER JOIN classes c ON c.id = s.class_id
             LEFT JOIN users performer ON performer.id = dh.performed_by
             LEFT JOIN stored_files sf ON sf.id = dh.evidence_file_id
             %s
             %s
             ORDER BY %s
             LIMIT :limit OFFSET :offset',
            $incidentCategorySelect,
            $letterSelect,
            $letterJoin,
            $whereSql,
            $orderBy
        );

        $statement = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countHistory(array $filters): int
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['student_id'])) {
            $where[] = 'dh.student_id = :student_id';
            $bindings['student_id'] = (int) $filters['student_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 's.class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['term'])) {
            $where[] = 'dh.term = :term';
            $bindings['term'] = (int) $filters['term'];
        }

        $sql = 'SELECT COUNT(*)
                FROM deductions_history dh
                INNER JOIN students s ON s.id = dh.student_id';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $statement = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function deleteHistoryEntry(int $historyId): bool
    {
        $statement = $this->db->prepare('DELETE FROM deductions_history WHERE id = :id');
        return $statement->execute(['id' => $historyId]);
    }

    public function deleteHistoryByFilters(array $filters): int
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['student_id'])) {
            $where[] = 'dh.student_id = :student_id';
            $bindings['student_id'] = (int) $filters['student_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 's.class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['term'])) {
            $where[] = 'dh.term = :term';
            $bindings['term'] = (int) $filters['term'];
        }

        $sql = 'DELETE dh FROM deductions_history dh INNER JOIN students s ON s.id = dh.student_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $statement = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->rowCount();
    }
}
