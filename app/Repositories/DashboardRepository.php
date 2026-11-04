<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DashboardRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function overview(): array
    {
        return [
            'students' => $this->countTable('students'),
            'classes' => $this->countTable('classes'),
            'categories' => $this->countTable('categories'),
            'roles' => $this->countTable('roles'),
        ];
    }

    public function riskDistribution(): array
    {
        if ($this->tableExists('student_risk_profiles')) {
            $statement = $this->db->query(
                'SELECT risk_level, COUNT(*) AS total
                 FROM student_risk_profiles
                 GROUP BY risk_level
                 ORDER BY total DESC'
            );

            return $statement->fetchAll();
        }

        // Fallback classification uses total remaining marks when AI risk profiles are not ready yet.
        $statement = $this->db->query(
            'SELECT
                CASE
                    WHEN SUM(m.marks) >= 100 THEN "Excellent"
                    WHEN SUM(m.marks) >= 60 THEN "Warning"
                    ELSE "Critical"
                END AS risk_level,
                COUNT(*) AS total
             FROM students s
             INNER JOIN student_marks m ON m.student_id = s.id
             GROUP BY risk_level
             ORDER BY total DESC'
        );

        return $statement->fetchAll();
    }

    public function topOffenders(int $limit = 5, ?int $term = null): array
    {
        if ($this->tableExists('deductions_history')) {
            $where = [];
            $bindings = [];

            if ($term !== null && $this->columnExists('deductions_history', 'term')) {
                $where[] = 'dh.term = :term';
                $bindings['term'] = $term;
            }

            $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
            $statement = $this->db->prepare(
                'SELECT s.id, s.full_name, s.student_code, c.name AS class_name,
                        COALESCE(SUM(CASE WHEN dh.marks_deducted > 0 THEN dh.marks_deducted ELSE 0 END), 0) AS total_deducted,
                        COUNT(CASE WHEN dh.marks_deducted > 0 THEN dh.id END) AS incidents
                 FROM deductions_history dh
                 INNER JOIN students s ON s.id = dh.student_id
                 INNER JOIN classes c ON c.id = s.class_id
                 ' . $whereSql . '
                 GROUP BY s.id, s.full_name, s.student_code, c.name
                 ORDER BY total_deducted DESC, incidents DESC, s.full_name ASC
                 LIMIT :limit'
            );
            foreach ($bindings as $key => $value) {
                $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll();
        }

        $marksImpact = 'GREATEST(0, 40 - m.marks)';
        $where = [];
        $bindings = [];

        if ($term !== null && $this->columnExists('student_marks', 'term')) {
            $where[] = 'm.term = :term';
            $bindings['term'] = $term;
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $statement = $this->db->prepare(
            'SELECT s.id, s.full_name, s.student_code, c.name AS class_name,
                    COALESCE(SUM(' . $marksImpact . '), 0) AS total_deducted,
                    COUNT(CASE WHEN m.marks < 40 THEN m.id END) AS incidents
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             INNER JOIN student_marks m ON m.student_id = s.id
             ' . $whereSql . '
             GROUP BY s.id, s.full_name, s.student_code, c.name
             ORDER BY total_deducted DESC, incidents DESC, s.full_name ASC
             LIMIT :limit'
        );
        foreach ($bindings as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function classComparison(): array
    {
        $statement = $this->db->query(
            'SELECT c.name AS class_name,
                    ROUND(AVG(student_totals.total_marks), 2) AS avg_marks
             FROM classes c
             INNER JOIN (
                SELECT s.class_id, s.id AS student_id, SUM(m.marks) AS total_marks
                FROM students s
                INNER JOIN student_marks m ON m.student_id = s.id
                GROUP BY s.class_id, s.id
             ) AS student_totals ON student_totals.class_id = c.id
             GROUP BY c.id, c.name
             ORDER BY avg_marks DESC'
        );

        return $statement->fetchAll();
    }

    private function countTable(string $table): int
    {
        $statement = $this->db->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
        return (int) $statement->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
