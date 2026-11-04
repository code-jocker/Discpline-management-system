<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

final class User
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function existsByEmail(string $email): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower($email)]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function existsByUsername(string $username): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $statement->execute(['username' => $username]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.full_name, u.username, u.email, u.password_hash, u.profile_picture, u.role_id, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE LOWER(u.email) = LOWER(:email)
             LIMIT 1'
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findByEmailOrUsername(string $login): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.full_name, u.username, u.email, u.password_hash, u.profile_picture, u.role_id, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email OR u.username = :username
             LIMIT 1'
        );
        $loginLower = strtolower($login);
        $statement->execute([
            'email' => $loginLower,
            'username' => $loginLower
        ]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.full_name, u.username, u.email, u.profile_picture, u.role_id, r.name AS role_name
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

    public function create(string $fullName, string $username, string $email, string $hashedPassword, int $roleId): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, role_id, created_at)
             VALUES (:full_name, :username, :email, :password, :role_id, NOW())'
        );

        $statement->execute([
            'full_name' => $fullName,
            'username' => $username,
            'email' => strtolower($email),
            'password' => $hashedPassword,
            'role_id' => $roleId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $hashedPassword): void
    {
        $statement = $this->db->prepare('UPDATE users SET password_hash = :password WHERE id = :id');
        $statement->execute([
            'password' => $hashedPassword,
            'id' => $userId,
        ]);
    }

    public function countUsers(): int
    {
        $statement = $this->db->query('SELECT COUNT(*) FROM users');
        return (int) $statement->fetchColumn();
    }

    public function getRoleOptions(): array
    {
        $statement = $this->db->prepare(
            'SELECT id, name
             FROM roles
             WHERE LOWER(name) <> :admin_role
             ORDER BY name ASC'
        );
        $statement->execute(['admin_role' => 'admin']);
        return $statement->fetchAll();
    }

    public function roleExists(int $roleId): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM roles WHERE id = :id');
        $statement->execute(['id' => $roleId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function getBootstrapAdminRoleId(): ?int
    {
        $preferredRoles = $this->resolvePrivilegedRoleNames();

        foreach ($preferredRoles as $roleName) {
            $statement = $this->db->prepare('SELECT id FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $statement->execute(['name' => $roleName]);
            $roleId = $statement->fetchColumn();

            if ($roleId !== false) {
                return (int) $roleId;
            }
        }

        return null;
    }

    public function userHasPrivilegedRole(int $userId): bool
    {
        $preferredRoles = $this->resolvePrivilegedRoleNames();
        if ($preferredRoles === []) {
            return false;
        }

        $placeholders = [];
        $bindings = ['user_id' => $userId];

        foreach ($preferredRoles as $index => $roleName) {
            $key = 'role_' . $index;
            $placeholders[] = 'LOWER(r.name) = LOWER(:' . $key . ')';
            $bindings[$key] = $roleName;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id
               AND (' . implode(' OR ', $placeholders) . ')'
        );
        $statement->execute($bindings);

        return (int) $statement->fetchColumn() > 0;
    }

    private function resolvePrivilegedRoleNames(): array
    {
        $configured = getenv('SBMS_PRIVILEGED_ROLE_NAMES') ?: 'admin,headteacher,it,deputy headteacher discipline,deputy headteacher in charge of discipline';
        $roles = array_filter(array_map('trim', explode(',', strtolower($configured))));

        return array_values(array_unique($roles));
    }

    public function storeRememberToken(int $userId, string $selector, string $hashedValidator, string $expiresAt): void
    {
        if (!\Database::tableExists('remember_tokens')) {
            return;
        }

        $statement = $this->db->prepare(
            'INSERT INTO remember_tokens (user_id, selector, hashed_validator, expires_at, created_at)
             VALUES (:user_id, :selector, :hashed_validator, :expires_at, NOW())'
        );
        $statement->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'hashed_validator' => $hashedValidator,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findRememberToken(string $selector): ?array
    {
        if (!\Database::tableExists('remember_tokens')) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT rt.id, rt.user_id, rt.hashed_validator, rt.expires_at, u.role_id
             FROM remember_tokens rt
             INNER JOIN users u ON u.id = rt.user_id
             WHERE rt.selector = :selector
             LIMIT 1'
        );
        $statement->execute(['selector' => $selector]);
        $token = $statement->fetch();

        return $token ?: null;
    }

    public function deleteRememberTokenById(int $tokenId): void
    {
        if (!\Database::tableExists('remember_tokens')) {
            return;
        }

        $statement = $this->db->prepare('DELETE FROM remember_tokens WHERE id = :id');
        $statement->execute(['id' => $tokenId]);
    }

    public function deleteRememberTokensForUser(int $userId): void
    {
        if (!\Database::tableExists('remember_tokens')) {
            return;
        }

        $statement = $this->db->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function generateAndStorePasswordResetToken(int $userId, int $ttlSeconds = 3600): string
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token); // Hash the token for storage
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        // Ensure any old reset tokens for this user are cleared first
        $this->deletePasswordResetTokensForUser($userId);

        if (!\Database::tableExists('password_resets')) {
            \Database::logError('Password reset store skipped: missing password_resets table', ['user_id' => $userId]);
            return $token;
        }

        $statement = $this->db->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, created_at)
             VALUES (:user_id, :token, :expires_at, NOW())'
        );
        $statement->execute([
            'user_id' => $userId,
            'token' => $hashedToken,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public function findUserIdByPasswordResetToken(string $token): ?int
    {
        if (!\Database::tableExists('password_resets')) {
            return null;
        }

        $hashedToken = hash('sha256', $token);

        $statement = $this->db->prepare(
            'SELECT user_id 
             FROM password_resets 
             WHERE token = :token AND expires_at > NOW() 
             LIMIT 1'
        );
        $statement->execute(['token' => $hashedToken]);
        $userId = $statement->fetchColumn();

        return $userId !== false ? (int) $userId : null;
    }

    public function deletePasswordResetTokensForUser(int $userId): void
    {
        if (!\Database::tableExists('password_resets')) {
            return;
        }

        $statement = $this->db->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function logAudit(?int $userId, string $action, string $ipAddress): void
    {
        if (!\Database::tableExists('audit_logs')) {
            return;
        }

        $timestampColumn = \Database::columnExists('audit_logs', 'timestamp') ? 'timestamp' : (\Database::columnExists('audit_logs', 'created_at') ? 'created_at' : null);
        $columns = ['user_id', 'action', 'ip_address'];
        $values = [':user_id', ':action', ':ip_address'];

        if ($timestampColumn !== null) {
            $columns[] = $timestampColumn;
            $values[] = 'NOW()';
        }

        $statement = $this->db->prepare(
            'INSERT INTO audit_logs (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')'
        );
        $statement->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':action', $action, PDO::PARAM_STR);
        $statement->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $statement->execute();
    }

    public function fetchAll(int $limit, int $offset): array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.full_name, u.username, u.email, u.role_id, r.name AS role_name, u.created_at
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function update(int $userId, string $fullName, string $username, string $email, int $roleId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 username = :username,
                 email = :email,
                 role_id = :role_id
             WHERE id = :id'
        );

        return $statement->execute([
            'full_name' => $fullName,
            'username' => $username,
            'email' => strtolower($email),
            'role_id' => $roleId,
            'id' => $userId,
        ]);
    }

    public function updateProfile(int $userId, string $fullName, string $username, string $email): bool
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 username = :username,
                 email = :email
             WHERE id = :id'
        );

        return $statement->execute([
            'full_name' => $fullName,
            'username' => $username,
            'email' => strtolower($email),
            'id' => $userId,
        ]);
    }

    public function updateProfilePicture(int $userId, string $path): bool
    {
        $statement = $this->db->prepare('UPDATE users SET profile_picture = :path WHERE id = :id');
        return $statement->execute([
            'path' => $path,
            'id' => $userId,
        ]);
    }

    public function delete(int $userId): bool
    {
        $this->db->beginTransaction();

        try {
            // Delete related records to maintain integrity if needed
            // e.g., remember_tokens, password_resets
            if (\Database::tableExists('remember_tokens')) {
                $stmt = $this->db->prepare('DELETE FROM remember_tokens WHERE user_id = :id');
                $stmt->execute(['id' => $userId]);
            }
            if (\Database::tableExists('password_resets')) {
                $stmt = $this->db->prepare('DELETE FROM password_resets WHERE user_id = :id');
                $stmt->execute(['id' => $userId]);
            }
            // Cannot easily delete audit logs as they are meant to be permanent,
            // but normally we might set user_id to NULL. For now let's just do that.
            if (\Database::tableExists('audit_logs')) {
                $stmt = $this->db->prepare('UPDATE audit_logs SET user_id = NULL WHERE user_id = :id');
                $stmt->execute(['id' => $userId]);
            }

            $statement = $this->db->prepare('DELETE FROM users WHERE id = :id');
            $result = $statement->execute(['id' => $userId]);

            $this->db->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}

