<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

final class AuditLog
{
    private PDO $db;
    private ?bool $descriptionColumnExists = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function log(?int $userId, string $action, string $table = '', int $recordId = 0, array $meta = []): void
    {
        $userId = $userId ?? 0;
        $description = trim("$table $recordId " . json_encode($meta));
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->create($userId, $action, $description, $ipAddress);
    }

    public function create(int $userId, string $action, string $description, string $ipAddress): void
    {
        if (!Database::tableExists('audit_logs')) {
            return;
        }

        $useDescription = $this->hasColumn('description');
        $timestampColumn = $this->timestampColumnName();

        if (!$useDescription) {
            $action = $action . ' | ' . $description;
        }

        $columns = ['user_id', 'action', 'ip_address'];
        $values = [':user_id', ':action', ':ip_address'];
        $bindings = [
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => $ipAddress,
        ];

        if ($useDescription) {
            $columns[] = 'description';
            $values[] = ':description';
            $bindings['description'] = $description;
        }

        if ($timestampColumn !== null) {
            $columns[] = $timestampColumn;
            $values[] = 'NOW()';
        }

        $statement = $this->db->prepare(
            'INSERT INTO audit_logs (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')'
        );
        $statement->execute($bindings);
    }

    private function hasColumn(string $columnName): bool
    {
        try {
            return Database::columnExists('audit_logs', $columnName);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function timestampColumnName(): ?string
    {
        if ($this->hasColumn('timestamp')) {
            return 'timestamp';
        }

        if ($this->hasColumn('created_at')) {
            return 'created_at';
        }

        return null;
    }

    private function hasDescriptionColumn(): bool
    {
        if ($this->descriptionColumnExists !== null) {
            return $this->descriptionColumnExists;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => 'audit_logs',
            'column_name' => 'description',
        ]);

        $this->descriptionColumnExists = (int) $statement->fetchColumn() > 0;
        return $this->descriptionColumnExists;
    }
}
