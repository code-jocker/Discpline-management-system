<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

final class Category
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        $statement = $this->db->query('SELECT id, name FROM categories ORDER BY name ASC');
        return $statement->fetchAll();
    }

    public function exists(int $categoryId): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
        $statement->execute(['id' => $categoryId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function create(array $payload): int
    {
        $statement = $this->db->prepare('INSERT INTO categories (name) VALUES (:name)');
        $statement->execute(['name' => $payload['name']]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $categoryId, array $payload): bool
    {
        $statement = $this->db->prepare('UPDATE categories SET name = :name WHERE id = :id');
        return $statement->execute([
            'name' => $payload['name'],
            'id' => $categoryId,
        ]);
    }

    public function delete(int $categoryId): bool
    {
        // Check if there are classes linked to this category
        $statement = $this->db->prepare('SELECT COUNT(*) FROM classes WHERE category_id = :category_id');
        $statement->execute(['category_id' => $categoryId]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new Exception("Cannot delete category because it has linked classes.");
        }

        $statement = $this->db->prepare('DELETE FROM categories WHERE id = :id');
        return $statement->execute(['id' => $categoryId]);
    }
}
