<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/models/Settings.php';

final class SettingsController
{
    private Settings $settings;

    public function __construct()
    {
        $this->settings = new Settings(Database::connection());
    }

    public function index(): array
    {
        return [
            'status' => 'success',
            'data' => $this->settings->getAll()
        ];
    }

    public function save(array $input): array
    {
        // Filter for specific allowed settings to prevent injection of arbitrary keys
        $allowedKeys = [
            'school_name',
            'current_term',
            'academic_year',
            'selected_school_year',
            'maintenance_mode',
            'low_marks_threshold',
            'low_marks_letter_enabled',
        ];
        $toUpdate = [];

        foreach ($allowedKeys as $key) {
            if (isset($input[$key])) {
                $toUpdate[$key] = trim((string)$input[$key]);
            }
        }

        try {
            $this->settings->update($toUpdate);
            return ['status' => 'success', 'message' => 'System settings updated successfully.'];
        } catch (Throwable $e) {
            Database::logError('Settings update failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to save settings.'];
        }
    }
}
