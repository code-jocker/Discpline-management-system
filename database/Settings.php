<?php
declare(strict_types=1);

final class Settings
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT setting_key, setting_value FROM system_settings');
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function update(array $settings): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) 
             VALUES (:key, :value) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($settings as $key => $value) {
            $stmt->execute(['key' => $key, 'value' => $value]);
        }
    }

    public function get(string $key, $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }
}