<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class StudentRepository
{
    private PDO $db;

    /** @var array<string, bool> */
    private static array $columnCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private static function hasColumn(string $column): bool
    {
        if (!array_key_exists($column, self::$columnCache)) {
            self::$columnCache[$column] = \Database::columnExists('students', $column);
        }

        return self::$columnCache[$column];
    }

    private static function selectParentContactFragment(): string
    {
        return self::hasColumn('parent_contact') ? 's.parent_contact, ' : '';
    }

    private static function selectDirectoryFragment(): string
    {
        $fragment = '';
        foreach (['stream', 'dormitory', 'status', 'school_year'] as $column) {
            if (self::hasColumn($column)) {
                $fragment .= 's.' . $column . ', ';
            }
        }

        return $fragment;
    }

    private static function selectProfileFragment(): string
    {
        $fragment = '';
        foreach (['date_of_birth', 'address', 'photo_path'] as $column) {
            if (self::hasColumn($column)) {
                $fragment .= 's.' . $column . ', ';
            }
        }

        return $fragment;
    }

    private static function selectConductFragment(): string
    {
        return '(SELECT sm.marks FROM student_marks sm
                 WHERE sm.student_id = s.id
                 ORDER BY (sm.school_year <=> s.school_year) DESC, sm.term DESC, sm.id DESC
                 LIMIT 1) AS conduct_marks,
                (SELECT sm.term FROM student_marks sm
                 WHERE sm.student_id = s.id
                 ORDER BY (sm.school_year <=> s.school_year) DESC, sm.term DESC, sm.id DESC
                 LIMIT 1) AS conduct_term';
    }

    private function selectColumns(): string
    {
        return 's.id, s.student_code, s.full_name, ' . self::selectParentContactFragment()
            . self::selectDirectoryFragment() . self::selectProfileFragment()
            . 's.class_id, s.category_id,
               c.name AS class_name, cat.name AS category_name,
               rp.risk_level, rp.incidents_count, rp.total_marks_deducted,
               ' . self::selectConductFragment();
    }

    private function joins(): string
    {
        return 'FROM students s
                INNER JOIN classes c ON c.id = s.class_id
                INNER JOIN categories cat ON cat.id = s.category_id
                LEFT JOIN student_risk_profiles rp ON rp.student_id = s.id';
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $bindings
     */
    private function buildWhere(array $filters, array &$bindings): string
    {
        $where = [];

        if (!empty($filters['class_id'])) {
            $where[] = 's.class_id = :class_id';
            $bindings['class_id'] = (int) $filters['class_id'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 's.category_id = :category_id';
            $bindings['category_id'] = (int) $filters['category_id'];
        }

        foreach (['stream', 'dormitory', 'status', 'school_year'] as $column) {
            if (!empty($filters[$column]) && self::hasColumn($column)) {
                $where[] = 's.' . $column . ' = :' . $column;
                $bindings[$column] = (string) $filters[$column];
            }
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            // Native prepares cannot reuse a named placeholder, so each column gets
            // its own bound copy of the term.
            $where[] = '(s.full_name LIKE :search_name OR s.student_code LIKE :search_code'
                . (self::hasColumn('parent_contact') ? ' OR s.parent_contact LIKE :search_phone' : '')
                . (ctype_digit($search) ? ' OR s.id = :search_id' : '') . ')';
            $bindings['search_name'] = '%' . $search . '%';
            $bindings['search_code'] = '%' . $search . '%';
            if (self::hasColumn('parent_contact')) {
                $bindings['search_phone'] = '%' . $search . '%';
            }
            if (ctype_digit($search)) {
                $bindings['search_id'] = (int) $search;
            }
        }

        return $where ? 'WHERE ' . implode(' AND ', $where) : '';
    }

    private function orderBy(array $filters): string
    {
        $sortable = [
            'name' => 's.full_name',
            'admission' => 's.student_code',
            'class' => 'c.name',
            'stream' => 's.stream',
            'dormitory' => 's.dormitory',
            'conduct' => 'conduct_marks',
            'risk' => 'FIELD(COALESCE(rp.risk_level, \'\'), \'High Risk\', \'Medium Risk\', \'Low Risk\')',
            'status' => 's.status',
            'year' => 's.school_year',
        ];

        $sort = (string) ($filters['sort'] ?? 'class');
        if (!isset($sortable[$sort])) {
            $sort = 'class';
        }
        $direction = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        return 'ORDER BY ' . $sortable[$sort] . ' ' . $direction
            . ', cat.name ASC, c.name ASC, s.full_name ASC';
    }

    public function paginate(array $filters, int $limit, int $offset): array
    {
        $bindings = [];
        $whereSql = $this->buildWhere($filters, $bindings);

        $statement = $this->db->prepare(
            'SELECT ' . $this->selectColumns() . '
             ' . $this->joins() . '
             ' . $whereSql . '
             ' . $this->orderBy($filters) . '
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bindings as $key => $value) {
            $type = is_string($value) ? PDO::PARAM_STR : PDO::PARAM_INT;
            $statement->bindValue(':' . $key, $value, $type);
        }

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(array $filters): int
    {
        $bindings = [];
        $whereSql = $this->buildWhere($filters, $bindings);

        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM students s ' . $whereSql
        );

        foreach ($bindings as $key => $value) {
            $type = is_string($value) ? PDO::PARAM_STR : PDO::PARAM_INT;
            $statement->bindValue(':' . $key, $value, $type);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findDetailed(int $studentId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . $this->selectColumns() . '
             ' . $this->joins() . '
             WHERE s.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $studentId]);
        $student = $statement->fetch();

        if (!$student) {
            return null;
        }

        $student['parent_contacts'] = $this->safeFetchAll(
            'SELECT parent_name, relationship, email, phone_number, whatsapp_number, is_primary, contact_preference
             FROM parent_contacts WHERE student_id = :id
             ORDER BY is_primary DESC, id ASC',
            ['id' => $studentId]
        );
        $student['marks_history'] = $this->safeFetchAll(
            'SELECT term, marks, school_year FROM student_marks
             WHERE student_id = :id
             ORDER BY school_year DESC, term DESC',
            ['id' => $studentId]
        );
        $student['deductions'] = $this->safeFetchAll(
            'SELECT d.id, d.term, d.marks_deducted, d.reason, d.incident_category, d.timestamp, d.school_year,
                    u.full_name AS performed_by_name
             FROM deductions_history d
             LEFT JOIN users u ON u.id = d.performed_by
             WHERE d.student_id = :id
             ORDER BY d.timestamp DESC, d.id DESC
             LIMIT 30',
            ['id' => $studentId]
        );
        $student['rewards'] = $this->safeFetchOne(
            'SELECT total_points, current_streak, last_update FROM student_rewards WHERE student_id = :id LIMIT 1',
            ['id' => $studentId]
        );
        $student['letters'] = $this->safeFetchAll(
            'SELECT id, term, score, subject, source, generated_at FROM student_letters
             WHERE student_id = :id
             ORDER BY generated_at DESC
             LIMIT 10',
            ['id' => $studentId]
        );
        $student['expulsion'] = $this->safeFetchOne(
            'SELECT reason, expelled_at FROM expulsions WHERE student_id = :id LIMIT 1',
            ['id' => $studentId]
        );

        return $student;
    }

    private function safeFetchAll(string $sql, array $bindings): array
    {
        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($bindings);
            return $statement->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function safeFetchOne(string $sql, array $bindings): ?array
    {
        $rows = $this->safeFetchAll($sql, $bindings);
        return $rows[0] ?? null;
    }

    /**
     * Real aggregate figures for the directory stat cards.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $hasStatus = self::hasColumn('status');
        $hasYear = self::hasColumn('school_year');
        $activeClause = $hasStatus ? "s.status = 'active'" : '1 = 1';

        $select = ['COUNT(*) AS total'];
        if ($hasStatus) {
            $select[] = "COALESCE(SUM(s.status = 'active'), 0) AS active";
            $select[] = "COALESCE(SUM(s.status = 'transferred'), 0) AS transferred";
            $select[] = "COALESCE(SUM(s.status = 'graduated'), 0) AS graduated";
            $select[] = "COALESCE(SUM(s.status = 'archived'), 0) AS archived";
        }
        if ($hasYear) {
            $select[] = 'COUNT(DISTINCT s.school_year) AS years';
        }

        $row = $this->safeFetchOne(
            'SELECT ' . implode(",\n", $select) . '
             FROM students s
             LEFT JOIN student_risk_profiles rp ON rp.student_id = s.id',
            []
        ) ?: [];

        $highRisk = $this->safeFetchOne(
            "SELECT COUNT(*) AS total FROM student_risk_profiles rp
             INNER JOIN students s ON s.id = rp.student_id
             WHERE rp.risk_level = 'High Risk' AND {$activeClause}",
            []
        );

        $avgRow = $this->safeFetchOne(
            "SELECT ROUND(AVG(t.conduct_marks), 1) AS average FROM (
                SELECT (SELECT sm.marks FROM student_marks sm
                        WHERE sm.student_id = s.id
                        ORDER BY (sm.school_year <=> s.school_year) DESC, sm.term DESC, sm.id DESC
                        LIMIT 1) AS conduct_marks
                FROM students s
                WHERE {$activeClause}
             ) t WHERE t.conduct_marks IS NOT NULL",
            []
        );

        $categories = $this->safeFetchAll(
            'SELECT cat.id, cat.name, COUNT(s.id) AS total
             FROM categories cat
             LEFT JOIN students s ON s.category_id = cat.id' . ($hasStatus ? " AND s.status = 'active'" : '') . '
             GROUP BY cat.id, cat.name
             ORDER BY cat.name ASC',
            []
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? ($row['total'] ?? 0)),
            'transferred' => (int) ($row['transferred'] ?? 0),
            'graduated' => (int) ($row['graduated'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
            'high_risk' => (int) ($highRisk['total'] ?? 0),
            'avg_conduct' => $avgRow['average'] !== null ? (float) $avgRow['average'] : null,
            'categories' => $categories,
        ];
    }

    /**
     * Distinct values used to populate the filter selects.
     *
     * @return array<string, array<int, string>>
     */
    public function filterOptions(): array
    {
        $options = ['streams' => [], 'dormitories' => [], 'school_years' => []];

        foreach (['stream' => 'streams', 'dormitory' => 'dormitories', 'school_year' => 'school_years'] as $column => $key) {
            if (!self::hasColumn($column)) {
                continue;
            }
            $rows = $this->safeFetchAll(
                'SELECT DISTINCT ' . $column . ' AS value FROM students
                 WHERE ' . $column . " IS NOT NULL AND {$column} <> ''
                 ORDER BY " . $column . ' ASC',
                []
            );
            $options[$key] = array_map(static fn (array $row): string => (string) $row['value'], $rows);
        }

        return $options;
    }
}
