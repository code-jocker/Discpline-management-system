<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

final class ClassModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(?int $categoryId = null): array
    {
        if ($categoryId !== null) {
            $statement = $this->db->prepare(
                'SELECT id, name, category_id, class_teacher_user_id
                 FROM classes
                 WHERE category_id = :category_id
                 ORDER BY name ASC'
            );
            $statement->execute(['category_id' => $categoryId]);

            return $statement->fetchAll();
        }

        $statement = $this->db->query('SELECT id, name, category_id, class_teacher_user_id FROM classes ORDER BY name ASC');
        return $statement->fetchAll();
    }

    public function existsInCategory(int $classId, int $categoryId): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM classes
             WHERE id = :id AND category_id = :category_id'
        );
        $statement->execute([
            'id' => $classId,
            'category_id' => $categoryId,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function exists(int $classId): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM classes WHERE id = :id');
        $statement->execute(['id' => $classId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function create(array $payload): int
    {
        $teacherId = isset($payload['class_teacher_user_id']) && $payload['class_teacher_user_id'] !== ''
            ? (int) $payload['class_teacher_user_id']
            : null;

        $statement = $this->db->prepare(
            'INSERT INTO classes (name, category_id, class_teacher_user_id)
             VALUES (:name, :category_id, :class_teacher_user_id)'
        );
        $statement->execute([
            'name' => $payload['name'],
            'category_id' => $payload['category_id'],
            'class_teacher_user_id' => $teacherId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $classId, array $payload): bool
    {
        $teacherId = isset($payload['class_teacher_user_id']) && $payload['class_teacher_user_id'] !== ''
            ? (int) $payload['class_teacher_user_id']
            : null;

        $statement = $this->db->prepare(
            'UPDATE classes
             SET name = :name,
                 category_id = :category_id,
                 class_teacher_user_id = :class_teacher_user_id
             WHERE id = :id'
        );
        return $statement->execute([
            'name' => $payload['name'],
            'category_id' => $payload['category_id'],
            'class_teacher_user_id' => $teacherId,
            'id' => $classId,
        ]);
    }

    public function assignTeacher(int $classId, int $teacherId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE classes
             SET class_teacher_user_id = :teacher_id
             WHERE id = :id'
        );

        return $statement->execute([
            'teacher_id' => $teacherId,
            'id' => $classId,
        ]);
    }

    public function delete(int $classId): bool
    {
        // Check if there are students linked to this class
        $statement = $this->db->prepare('SELECT COUNT(*) FROM students WHERE class_id = :class_id');
        $statement->execute(['class_id' => $classId]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new Exception("Cannot delete class because it has linked students.");
        }

        $statement = $this->db->prepare('DELETE FROM classes WHERE id = :id');
        return $statement->execute(['id' => $classId]);
    }
}
