<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.full_name, u.email, u.password_hash, u.role_id, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => strtolower($email)]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.full_name, u.email, u.role_id, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function hasPermission(int $userId, string $permissionName): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM users u
             INNER JOIN role_permissions rp ON rp.role_id = u.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = :user_id
               AND LOWER(p.name) = LOWER(:permission_name)'
        );
        $statement->execute([
            'user_id' => $userId,
            'permission_name' => $permissionName,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }
}
