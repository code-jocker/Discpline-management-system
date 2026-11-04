<?php

declare(strict_types=1);

final class SchoolYear
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function ensureTable(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS school_years (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(20) NOT NULL,
                start_year SMALLINT UNSIGNED NOT NULL,
                end_year SMALLINT UNSIGNED NOT NULL,
                start_month TINYINT UNSIGNED NOT NULL DEFAULT 9,
                end_month TINYINT UNSIGNED NOT NULL DEFAULT 7,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_school_year_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE INDEX IF NOT EXISTS idx_school_years_active
             ON school_years (is_active, is_archived, is_locked)"
        );

        $defaultName = self::calculateCurrentAcademicYear();
        $existing = $db->prepare('SELECT id FROM school_years WHERE name = :name LIMIT 1');
        $existing->execute(['name' => $defaultName]);

        if ($existing->fetchColumn() === false) {
            $db->prepare(
                'INSERT INTO school_years (name, start_year, end_year, start_month, end_month, is_active)
                 VALUES (:name, :start_year, :end_year, 9, 7, 1)'
            )->execute([
                'name' => $defaultName,
                'start_year' => (int) substr($defaultName, 0, 4),
                'end_year' => (int) substr($defaultName, 5, 4),
            ]);
        }

        $db->exec('UPDATE school_years SET is_active = 0 WHERE is_active = 1 AND id != (SELECT id FROM (SELECT id FROM school_years WHERE is_active = 1 ORDER BY start_year DESC LIMIT 1) x)');

        // Ensure student_marks and related tables have a `school_year` column for multi-year data
        try {
            $hasStudentMarks = (bool) $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'student_marks' AND column_name = 'school_year'")->fetchColumn();
            if (!$hasStudentMarks) {
                $db->exec("ALTER TABLE student_marks ADD COLUMN school_year VARCHAR(20) DEFAULT NULL");
                // Backfill existing rows with the current selected school year if settings table exists
                $hasSettings = (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'settings'")->fetchColumn();
                if ($hasSettings) {
                    $stmt = $db->query("SELECT value FROM settings WHERE key_name = 'selected_school_year' LIMIT 1");
                    $selected = $stmt ? $stmt->fetchColumn() : null;
                    if ($selected) {
                        $upd = $db->prepare('UPDATE student_marks SET school_year = :sy WHERE school_year IS NULL');
                        $upd->execute(['sy' => $selected]);
                    }
                }
            }

            $hasDeductions = (bool) $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'deductions_history' AND column_name = 'school_year'")->fetchColumn();
            if (!$hasDeductions && (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'deductions_history'")->fetchColumn()) {
                $db->exec("ALTER TABLE deductions_history ADD COLUMN school_year VARCHAR(20) DEFAULT NULL");
            }

            $hasRisk = (bool) $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'student_risk_profiles' AND column_name = 'school_year'")->fetchColumn();
            if (!$hasRisk && (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'student_risk_profiles'")->fetchColumn()) {
                $db->exec("ALTER TABLE student_risk_profiles ADD COLUMN school_year VARCHAR(20) DEFAULT NULL");
            }
        } catch (Throwable $e) {
            // Non-fatal; log and continue.
            error_log('[SchoolYear::ensureTable] migration error: ' . $e->getMessage());
        }
    }

    public static function terms(): array
    {
        return [1, 2, 3];
    }

    public static function normalizeTerm($term): int
    {
        $normalized = (int) $term;
        if (!in_array($normalized, self::terms(), true)) {
            return 1;
        }

        return $normalized;
    }

    public static function calculateCurrentAcademicYear(?DateTimeInterface $date = null): string
    {
        $current = $date ?? new DateTimeImmutable('now');
        $month = (int) $current->format('n');
        $year = (int) $current->format('Y');

        if ($month >= 9) {
            return sprintf('%d-%d', $year, $year + 1);
        }

        return sprintf('%d-%d', $year - 1, $year);
    }

    public static function parseName(string $name): array
    {
        $trimmed = trim($name);
        if (preg_match('/^(\d{4})-(\d{4})$/', $trimmed, $matches) !== 1) {
            return [
                'name' => $trimmed,
                'start_year' => (int) date('Y'),
                'end_year' => (int) date('Y') + 1,
                'start_month' => 9,
                'end_month' => 7,
            ];
        }

        return [
            'name' => $trimmed,
            'start_year' => (int) $matches[1],
            'end_year' => (int) $matches[2],
            'start_month' => 9,
            'end_month' => 7,
        ];
    }

    public function all(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM school_years ORDER BY start_year DESC, end_year DESC, id DESC'
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function byId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM school_years WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function byName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM school_years WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => trim($name)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function active(): ?array
    {
        $stmt = $this->db->query(
            'SELECT * FROM school_years WHERE is_active = 1 AND is_archived = 0 ORDER BY start_year DESC LIMIT 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return $row ?: null;
    }

    public function resolveSelected(Settings $settings): array
    {
        $selected = trim((string) $settings->get('selected_school_year', ''));

        if ($selected !== '') {
            $year = $this->byName($selected);
            if ($year) {
                return $year;
            }
        }

        $active = $this->active();
        if ($active) {
            return $active;
        }

        $current = $this->byName(self::calculateCurrentAcademicYear());
        if ($current) {
            return $current;
        }

        $created = $this->create(self::calculateCurrentAcademicYear());
        return $created;
    }

    public function create(string $name): array
    {
        $parsed = self::parseName($name);
        $year = $this->byName($parsed['name']);
        if ($year) {
            return $year;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO school_years (name, start_year, end_year, start_month, end_month, is_active)
             VALUES (:name, :start_year, :end_year, :start_month, :end_month, 0)'
        );
        $stmt->execute([
            'name' => $parsed['name'],
            'start_year' => $parsed['start_year'],
            'end_year' => $parsed['end_year'],
            'start_month' => $parsed['start_month'],
            'end_month' => $parsed['end_month'],
        ]);

        return $this->byName($parsed['name']) ?? [
            'id' => (int) $this->db->lastInsertId(),
            'name' => $parsed['name'],
            'start_year' => $parsed['start_year'],
            'end_year' => $parsed['end_year'],
            'start_month' => $parsed['start_month'],
            'end_month' => $parsed['end_month'],
            'is_active' => 0,
            'is_archived' => 0,
            'is_locked' => 0,
        ];
    }

    public function activate(int $id): void
    {
        $this->db->prepare('UPDATE school_years SET is_active = 0 WHERE is_active = 1')->execute();
        $this->db->prepare('UPDATE school_years SET is_active = 1 WHERE id = :id')->execute(['id' => $id]);

        $year = $this->byId($id);
        if ($year) {
            $this->db->prepare('UPDATE school_years SET is_archived = 0 WHERE id = :id')->execute(['id' => $id]);
        }
    }

    public function archive(int $id): void
    {
        $this->db->prepare('UPDATE school_years SET is_archived = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    public function reopen(int $id): void
    {
        $this->db->prepare('UPDATE school_years SET is_archived = 0 WHERE id = :id')->execute(['id' => $id]);
    }

    public function lock(int $id): void
    {
        $this->db->prepare('UPDATE school_years SET is_locked = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    public function unlock(int $id): void
    {
        $this->db->prepare('UPDATE school_years SET is_locked = 0 WHERE id = :id')->execute(['id' => $id]);
    }

    public function setSelectedName(Settings $settings, string $name): void
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return;
        }

        $settings->update(['selected_school_year' => $trimmed]);
        $year = $this->byName($trimmed);
        if ($year) {
            $settings->update(['active_academic_year' => $trimmed]);
        }
    }
}
