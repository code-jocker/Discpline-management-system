<?php
declare(strict_types=1);

/**
 * Settings model for managing system-wide configuration stored in the database.
 */
final class Settings
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieves all settings as an associative key-value array.
     */
    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT `key_name`, `value` FROM settings');
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    /**
     * Retrieves a single setting value by key.
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $stmt = $this->db->prepare('SELECT `value` FROM settings WHERE `key_name` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null) ? $value : $default;
    }

    /**
     * Updates settings in bulk using an upsert (Insert or Update) approach.
     *
     * @param array $data Associative array of settings to save.
     */
    public function update(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (`key_name`, `value`) 
             VALUES (:key, :value) 
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );

        foreach ($data as $key => $value) {
            $stmt->execute([
                'key' => (string) $key,
                'value' => (string) $value
            ]);
        }
    }

    public function currentTerm(): int
    {
        return SchoolYear::normalizeTerm($this->get('current_term', 1));
    }

    public function selectedSchoolYear(): string
    {
        $selected = trim((string) $this->get('selected_school_year', ''));
        if ($selected !== '') {
            return $selected;
        }

        $defaultYear = SchoolYear::calculateCurrentAcademicYear();
        $this->update(['selected_school_year' => $defaultYear]);
        return $defaultYear;
    }

    public function academicContext(): array
    {
        return [
            'school_year' => $this->selectedSchoolYear(),
            'term' => $this->currentTerm(),
        ];
    }
}