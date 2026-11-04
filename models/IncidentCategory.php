<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

/**
 * Incident categories for discipline actions (deduction / reward presets).
 * Distinct from the `categories` table, which holds academic tracks.
 */
final class IncidentCategory
{
    public const ACTION_DEDUCT = 'deduct';
    public const ACTION_ADD = 'add';

    public const SIGNAL_LATE = 'late';
    public const SIGNAL_ABSENCE = 'absence';
    public const SIGNAL_CONDUCT = 'conduct';
    public const SIGNAL_POSITIVE = 'positive';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array{0:string,1:string,2:int,3:string,4:int}>
     */
    public static function defaults(): array
    {
        return [
            ['Attendance', self::ACTION_DEDUCT, 2, self::SIGNAL_ABSENCE, 5],
            ['Late Arrival', self::ACTION_DEDUCT, 1, self::SIGNAL_LATE, 10],
            ['Absenteeism', self::ACTION_DEDUCT, 3, self::SIGNAL_ABSENCE, 20],
            ['Truancy', self::ACTION_DEDUCT, 5, self::SIGNAL_ABSENCE, 30],
            ['Uniform', self::ACTION_DEDUCT, 1, self::SIGNAL_CONDUCT, 40],
            ['Classroom Behaviour', self::ACTION_DEDUCT, 2, self::SIGNAL_CONDUCT, 50],
            ['Insubordination', self::ACTION_DEDUCT, 4, self::SIGNAL_CONDUCT, 60],
            ['Academic Integrity', self::ACTION_DEDUCT, 5, self::SIGNAL_CONDUCT, 70],
            ['Fighting', self::ACTION_DEDUCT, 8, self::SIGNAL_CONDUCT, 80],
            ['Bullying', self::ACTION_DEDUCT, 6, self::SIGNAL_CONDUCT, 90],
            ['Property Damage', self::ACTION_DEDUCT, 5, self::SIGNAL_CONDUCT, 100],
            ['Positive Behaviour', self::ACTION_ADD, 2, self::SIGNAL_POSITIVE, 110],
            ['Punctuality', self::ACTION_ADD, 1, self::SIGNAL_POSITIVE, 120],
            ['Leadership', self::ACTION_ADD, 3, self::SIGNAL_POSITIVE, 130],
            ['Community Service', self::ACTION_ADD, 3, self::SIGNAL_POSITIVE, 140],
            ['Academic Achievement', self::ACTION_ADD, 5, self::SIGNAL_POSITIVE, 150],
        ];
    }

    public function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS incident_categories (
                id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
                name          VARCHAR(100)   NOT NULL,
                action_type   VARCHAR(10)    NOT NULL DEFAULT "deduct",
                default_marks TINYINT UNSIGNED NOT NULL DEFAULT 1,
                signal_type   VARCHAR(20)    DEFAULT NULL,
                is_active     TINYINT(1)     NOT NULL DEFAULT 1,
                sort_order    INT            NOT NULL DEFAULT 0,
                created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_incident_category_name (name),
                KEY idx_incident_category_signal (signal_type),
                KEY idx_incident_category_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function seed(): void
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO incident_categories (name, action_type, default_marks, signal_type, sort_order)
             VALUES (:name, :action_type, :default_marks, :signal_type, :sort_order)'
        );

        foreach (self::defaults() as [$name, $actionType, $defaultMarks, $signalType, $sortOrder]) {
            $statement->execute([
                'name' => $name,
                'action_type' => $actionType,
                'default_marks' => $defaultMarks,
                'signal_type' => $signalType,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, action_type, default_marks, signal_type
             FROM incident_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, name ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * Count of recorded incidents for a student grouped by attendance signal.
     * Scoped to the term only: deductions_history rows predate school-year
     * tagging, so filtering on it would silently under-count.
     *
     * @return array{late:int, absence:int, positive:int}
     */
    public function attendanceSignals(int $studentId, int $term): array
    {
        $statement = $this->db->prepare(
            'SELECT ic.signal_type AS signal_key, COUNT(*) AS total
             FROM deductions_history dh
             INNER JOIN incident_categories ic ON ic.name = dh.incident_category
             WHERE dh.student_id = :student_id AND dh.term = :term
               AND ic.signal_type IN ("late", "absence", "positive")
             GROUP BY ic.signal_type'
        );
        $statement->execute(['student_id' => $studentId, 'term' => $term]);

        $counts = ['late' => 0, 'absence' => 0, 'positive' => 0];
        foreach ($statement->fetchAll() as $row) {
            $key = (string) ($row['signal_key'] ?? '');
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) $row['total'];
            }
        }

        return $counts;
    }
}
