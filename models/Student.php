<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
if (!class_exists('Settings')) {
    require_once dirname(__DIR__) . '/models/Settings.php';
}

final class Student
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function hasParentContactColumn(): bool
    {
        return \Database::columnExists('students', 'parent_contact');
    }

    private function selectParentContactFragment(): string
    {
        return $this->hasParentContactColumn() ? 's.parent_contact, ' : '';
    }

    /**
     * ID-card profile columns. Detected once per request because every check hits
     * information_schema, and older installs may not have run the migration yet.
     */
    private function profileColumns(): array
    {
        static $columns = null;

        if ($columns === null) {
            $wanted = ['date_of_birth', 'address', 'photo_path'];
            $columns = array_values(array_filter(
                $wanted,
                static fn (string $column): bool => \Database::columnExists('students', $column)
            ));
        }

        return $columns;
    }

    private function selectProfileFragment(): string
    {
        $fragment = '';
        foreach ($this->profileColumns() as $column) {
            $fragment .= 's.' . $column . ', ';
        }

        return $fragment;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $bindings
     */
    private function bindProfileValues(array $payload, array &$bindings): void
    {
        foreach ($this->profileColumns() as $column) {
            $value = $payload[$column] ?? null;

            if ($column === 'date_of_birth') {
                $value = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
            } else {
                $value = trim((string) $value);
                $value = $value === '' ? null : $value;
            }

            $bindings[$column] = $value;
        }
    }

    public function generateStudentCode(int $classId): string
    {
        return $this->nextStudentCodeForClass($classId);
    }

    /**
     * Re-sequence student codes for every class so each class keeps its own 1..N numbering.
     */
    public function normalizeAllStudentCodesByClass(): int
    {
        try {
            $statement = $this->db->query(
                'SELECT DISTINCT class_id
                 FROM students
                 ORDER BY class_id ASC'
            );
            $classIds = $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];

            $normalizedClasses = 0;
            foreach ($classIds as $classId) {
                $classId = (int) $classId;
                if ($classId <= 0) {
                    continue;
                }

                $this->reindexStudentCodesForClass($classId);
                $normalizedClasses++;
            }

            return $normalizedClasses;
        } catch (Throwable $exception) {
            error_log('Failed to normalize student codes by class: ' . $exception->getMessage());
            return 0;
        }
    }

    private function createTemporaryStudentCode(): string
    {
        return substr((string) uniqid('tmp_', true), 0, 30);
    }

    private function nextStudentCodeForClass(int $classId): string
    {
        $this->reindexStudentCodesForClass($classId);

        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM students WHERE class_id = :class_id'
        );
        $statement->execute(['class_id' => $classId]);

        $count = (int) $statement->fetchColumn();

        return (string) ($count + 1);
    }

    private function reindexStudentCodesForClass(int $classId): void
    {
        $statement = $this->db->prepare(
            'SELECT id, student_code
             FROM students
             WHERE class_id = :class_id
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['class_id' => $classId]);
        $students = $statement->fetchAll();

        if (!$students) {
            return;
        }

        $updateStatement = $this->db->prepare(
            'UPDATE students SET student_code = :student_code WHERE id = :id'
        );

        // Reindex also runs inside create()/update()/delete() transactions, so only
        // open one when the caller is not already in a transaction.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($students as $student) {
                $updateStatement->execute([
                    'student_code' => '__tmp_' . $classId . '_' . $student['id'],
                    'id' => $student['id'],
                ]);
            }

            $code = 1;
            foreach ($students as $student) {
                $nextCode = (string) $code;
                if ((string) ($student['student_code'] ?? '') !== $nextCode) {
                    $updateStatement->execute([
                        'student_code' => $nextCode,
                        'id' => $student['id'],
                    ]);
                }
                $code++;
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function studentCodeFromId(int $studentId): string
    {
        return (string) $studentId;
    }

    public function create(array $payload): int
    {
        // Student and marks initialization stay in one transaction to prevent partial enrollment records.
        $this->db->beginTransaction();

        try {
            $temporaryCode = $this->createTemporaryStudentCode();
            $hasParentContact = $this->hasParentContactColumn();
            $profileColumns = $this->profileColumns();
            $profileList = $profileColumns ? ', ' . implode(', ', $profileColumns) : '';
            $profileValues = $profileColumns ? ', :' . implode(', :', $profileColumns) : '';

            $statement = $this->db->prepare(
                'INSERT INTO students (student_code, full_name, class_id, category_id' . ($hasParentContact ? ', parent_contact' : '') . $profileList . ', created_at)
                 VALUES (:student_code, :full_name, :class_id, :category_id' . ($hasParentContact ? ', :parent_contact' : '') . $profileValues . ', NOW())'
            );

            $bindings = [
                'student_code' => $temporaryCode,
                'full_name' => $payload['full_name'],
                'class_id' => $payload['class_id'],
                'category_id' => $payload['category_id'],
            ];

            if ($hasParentContact) {
                $bindings['parent_contact'] = $payload['parent_contact'] ?? '';
            }

            $this->bindProfileValues($payload, $bindings);

            $statement->execute($bindings);

            $studentId = (int) $this->db->lastInsertId();
            $studentCode = $this->generateStudentCode((int) $payload['class_id']);

            $updateCodeStatement = $this->db->prepare(
                'UPDATE students SET student_code = :student_code WHERE id = :id'
            );
            $updateCodeStatement->execute([
                'student_code' => $studentCode,
                'id' => $studentId,
            ]);

            $this->initializeMarks($studentId);
            $this->db->commit();

            return $studentId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function update(int $studentId, array $payload): bool
    {
        $existingStudent = $this->find($studentId);
        if (!$existingStudent) {
            return false;
        }

        $newClassId = isset($payload['class_id']) ? (int) $payload['class_id'] : (int) $existingStudent['class_id'];
        $classChanged = $newClassId !== (int) $existingStudent['class_id'];

        if ($classChanged) {
            $payload['student_code'] = $this->generateStudentCode($newClassId);
        } else {
            unset($payload['student_code']);
        }

        $fieldFragment = $this->hasParentContactColumn() ? ', parent_contact = :parent_contact' : '';
        $codeFragment = isset($payload['student_code']) ? ', student_code = :student_code' : '';

        $profileFragment = '';
        foreach ($this->profileColumns() as $column) {
            $profileFragment .= ', ' . $column . ' = :' . $column;
        }

        $statement = $this->db->prepare(
            'UPDATE students
             SET full_name = :full_name,
                 class_id = :class_id,
                 category_id = :category_id' . $fieldFragment . $codeFragment . $profileFragment . '
             WHERE id = :id'
        );

        $bindings = [
            'full_name' => $payload['full_name'],
            'class_id' => $newClassId,
            'category_id' => $payload['category_id'],
            'id' => $studentId,
        ];

        if ($this->hasParentContactColumn()) {
            $bindings['parent_contact'] = $payload['parent_contact'] ?? '';
        }

        $this->bindProfileValues(array_merge($existingStudent, $payload), $bindings);

        if (isset($payload['student_code'])) {
            $bindings['student_code'] = $payload['student_code'];
        }

        $this->db->beginTransaction();
        try {
            $result = $statement->execute($bindings);
            if ($classChanged) {
                $this->reindexStudentCodesForClass((int) $existingStudent['class_id']);
                $this->reindexStudentCodesForClass($newClassId);
            }
            $this->db->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function existsByStudentCode(string $studentCode, int $classId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM students WHERE student_code = :student_code AND class_id = :class_id';
        $params = [
            'student_code' => $studentCode,
            'class_id' => $classId,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }

    public function delete(int $studentId): bool
    {
        $existingStudent = $this->find($studentId);
        if (!$existingStudent) {
            return false;
        }

        $classId = (int) $existingStudent['class_id'];

        $this->db->beginTransaction();

        try {
            // Delete all related records first to satisfy foreign key constraints
            $tables = [
                'deductions_history',
                'student_marks',
                'student_risk_profiles',
                'ai_predictions',
                'annual_discipline_reports',
                'attendance',
                'behavior_incidents',
                'discipline_marks',
                'parents',
                'positive_behavior'
            ];

            foreach ($tables as $table) {
                // Check if table exists to prevent errors
                $check = $this->db->query("SHOW TABLES LIKE '{$table}'");
                if ($check->rowCount() > 0) {
                    $stmt = $this->db->prepare("DELETE FROM {$table} WHERE student_id = :student_id");
                    $stmt->execute(['student_id' => $studentId]);
                }
            }

            // Finally, delete the student
            $deleteStudent = $this->db->prepare('DELETE FROM students WHERE id = :id');
            $deleteStudent->execute(['id' => $studentId]);

            if ($deleteStudent->rowCount() > 0) {
                $this->reindexStudentCodesForClass($classId);
            }

            $this->db->commit();
            return $deleteStudent->rowCount() > 0;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function exists(int $studentId): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM students WHERE id = :id');
        $statement->execute(['id' => $studentId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function find(int $studentId): ?array
    {
        $selectFragment = $this->selectParentContactFragment() . $this->selectProfileFragment();
        $statement = $this->db->prepare(
            'SELECT s.id, s.student_code, s.full_name, ' . $selectFragment . 's.class_id, s.category_id,
                    c.name AS class_name, cat.name AS category_name
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             INNER JOIN categories cat ON cat.id = s.category_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $studentId]);
        $student = $statement->fetch();

        return $student ?: null;
    }

    public function fetchAll(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['category_id'])) {
            $where[] = 's.category_id = :category_id';
            $bindings['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 's.class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $selectFragment = $this->selectParentContactFragment() . $this->selectProfileFragment();

        // Fetch relational labels in the same query so the UI does not trigger N+1 lookups.
        $sql = sprintf(
            'SELECT s.id, s.student_code, s.full_name, ' . $selectFragment . 's.class_id, s.category_id,
                    c.name AS class_name, cat.name AS category_name
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             INNER JOIN categories cat ON cat.id = s.category_id
             %s
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT :limit OFFSET :offset',
            $whereSql
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

    public function countAll(array $filters): int
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'category_id = :category_id';
            $bindings['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['class_id'])) {
            $where[] = 'class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        $sql = 'SELECT COUNT(*) FROM students';
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

    public function listForSelection(?int $classId = null, int $limit = 500): array
    {
        if ($classId !== null) {
            $statement = $this->db->prepare(
                'SELECT s.id, s.student_code, s.full_name, s.class_id, c.name AS class_name
                 FROM students s
                 INNER JOIN classes c ON c.id = s.class_id
                 WHERE s.class_id = :class_id
                   AND s.id NOT IN (SELECT student_id FROM expulsions)
                 ORDER BY s.full_name ASC
                 LIMIT :limit'
            );
            $statement->bindValue(':class_id', $classId, PDO::PARAM_INT);
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll();
        }

        $statement = $this->db->prepare(
            'SELECT s.id, s.student_code, s.full_name, s.class_id, c.name AS class_name
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             WHERE s.id NOT IN (SELECT student_id FROM expulsions)
             ORDER BY s.full_name ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function initializeMarks(int $studentId): void
    {
        $student = $this->find($studentId);
        $classId = (int) ($student['class_id'] ?? 0);
        $settings = new \Settings($this->db);
        $defaultMarks = 40;

        if ($classId > 0) {
            $stored = $settings->get('class_default_marks_' . $classId, '40');
            $defaultMarks = max(0, min(40, (int) $stored));
        }

        // Every student starts with the same term baseline so downstream modules can rely on complete term rows.
        $statement = $this->db->prepare(
            'INSERT INTO student_marks (student_id, term, marks)
             VALUES (:student_id, :term, :marks)'
        );

        for ($term = 1; $term <= 3; $term++) {
            // Include selected school year when the column exists
            if (\Database::columnExists('student_marks', 'school_year')) {
                $statement = $this->db->prepare(
                    'INSERT INTO student_marks (student_id, term, marks, school_year)
                     VALUES (:student_id, :term, :marks, :school_year)'
                );
                $statement->execute([
                    'student_id' => $studentId,
                    'term' => $term,
                    'marks' => $defaultMarks,
                    'school_year' => (new \Settings($this->db))->selectedSchoolYear(),
                ]);
            } else {
                $statement->execute([
                    'student_id' => $studentId,
                    'term' => $term,
                    'marks' => $defaultMarks,
                ]);
            }
        }
    }


    public function existsByNameAndClass(string $fullName, int $classId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM students WHERE LOWER(full_name) = LOWER(:full_name) AND class_id = :class_id';
        $params = [
            'full_name' => $fullName,
            'class_id' => $classId
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }
}
