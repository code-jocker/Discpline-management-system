<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';

final class UserController
{
    private PDO $db;
    private User $users;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->users = new User($this->db);
        $this->auditLogs = new AuditLog($this->db);
    }

    public function canManageUsers(?array $authUser): bool
    {
        if (!$authUser) {
            return false;
        }

        // Allow any role that has the manage_users permission.
        return $this->users->hasPermission((int) $authUser['id'], 'manage_users');
    }

    public function indexData(): array
    {
        try {
            return [
                'roles' => $this->users->getRoleOptions(),
            ];
        } catch (Throwable $exception) {
            Database::logError('User index data load failed', ['exception' => $exception->getMessage()]);
            return [
                'roles' => [],
            ];
        }
    }

    public function fetch(array $params): array
    {
        try {
            $total = $this->users->countUsers();
            $page = 1;
            $limit = max(1, $total);
            $offset = 0;

            $usersList = $total > 0 ? $this->users->fetchAll($limit, $offset) : [];

            $teacherIds = [];
            foreach ($usersList as $u) {
                if (stripos((string) $u['role_name'], 'teacher') !== false) {
                    $teacherIds[] = (int) $u['id'];
                }
            }
            $teacherIds = array_values(array_unique($teacherIds));

            $classCounts = [];
            if (!empty($teacherIds)) {
                $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
                $statement = $this->db->prepare(
                    "SELECT class_teacher_user_id, COUNT(*) AS class_count
                     FROM classes
                     WHERE class_teacher_user_id IN ($placeholders)
                     GROUP BY class_teacher_user_id"
                );
                $statement->execute($teacherIds);
                foreach ($statement->fetchAll() as $row) {
                    $classCounts[$row['class_teacher_user_id']] = (int) $row['class_count'];
                }
            }

            // Sanitize response, don't leak hashes
            $sanitized = [];
            foreach ($usersList as $u) {
                $sanitized[] = [
                    'id' => $u['id'],
                    'full_name' => $u['full_name'],
                    'username' => $u['username'],
                    'email' => $u['email'],
                    'role_id' => $u['role_id'],
                    'role_name' => $u['role_name'],
                    'created_at' => $u['created_at'],
                    'assigned_class_count' => $classCounts[$u['id']] ?? 0,
                ];
            }

            return [
                'status' => 'success',
                'data' => [
                    'items' => $sanitized,
                    'meta' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => 1,
                    ],
                ],
            ];
        } catch (Throwable $exception) {
            Database::logError('User fetch failed', ['exception' => $exception->getMessage(), 'params' => $params]);
            return [
                'status' => 'error',
                'message' => 'Failed to fetch users.',
            ];
        }
    }

    public function create(array $data, ?array $authUser): array
    {
        if (!$this->canManageUsers($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            $requiredFields = ['full_name', 'username', 'email', 'password', 'role_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return ['status' => 'error', 'message' => "Field '{$field}' is required."];
                }
            }

            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return ['status' => 'error', 'message' => 'Invalid email address.'];
            }

            if (strlen($data['password']) < 8) {
                return ['status' => 'error', 'message' => 'Password must be at least 8 characters.'];
            }

            if ($this->users->existsByEmail($data['email'])) {
                return ['status' => 'error', 'message' => 'Email is already taken.'];
            }

            if ($this->users->existsByUsername($data['username'])) {
                return ['status' => 'error', 'message' => 'Username is already taken.'];
            }

            if (!$this->users->roleExists((int) $data['role_id'])) {
                return ['status' => 'error', 'message' => 'Selected role does not exist.'];
            }

            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $userId = $this->users->create($data['full_name'], $data['username'], $data['email'], $hashedPassword, (int) $data['role_id']);

            $this->auditLogs->log(
                $authUser['id'] ?? null,
                'user_created',
                'users',
                $userId,
                ['email' => $data['email']]
            );

            return ['status' => 'success', 'message' => 'User created successfully.'];
        } catch (Throwable $exception) {
            Database::logError('User creation failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to create user.'];
        }
    }

    public function update(array $data, ?array $authUser): array
    {
        if (!$this->canManageUsers($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid user ID is required.'];
            }

            $userId = (int) $data['id'];
            $existing = $this->users->findById($userId);
            if (!$existing) {
                return ['status' => 'error', 'message' => 'User not found.'];
            }

            if (!empty($data['email']) && strtolower($data['email']) !== strtolower($existing['email'])) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    return ['status' => 'error', 'message' => 'Invalid email address.'];
                }
                if ($this->users->existsByEmail($data['email'])) {
                    return ['status' => 'error', 'message' => 'Email is already taken.'];
                }
            }

            if (!empty($data['username']) && $data['username'] !== $existing['username']) {
                if ($this->users->existsByUsername($data['username'])) {
                    return ['status' => 'error', 'message' => 'Username is already taken.'];
                }
            }

            $roleId = isset($data['role_id']) ? (int) $data['role_id'] : $existing['role_id'];
            if (!$this->users->roleExists($roleId)) {
                return ['status' => 'error', 'message' => 'Selected role does not exist.'];
            }

            $fullName = !empty($data['full_name']) ? $data['full_name'] : $existing['full_name'];
            $username = !empty($data['username']) ? $data['username'] : $existing['username'];
            $email = !empty($data['email']) ? $data['email'] : $existing['email'];

            $this->users->update($userId, $fullName, $username, $email, $roleId);

            if (!empty($data['password'])) {
                if (strlen($data['password']) < 8) {
                    return ['status' => 'error', 'message' => 'Password must be at least 8 characters.'];
                }
                $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
                $this->users->updatePasswordHash($userId, $hashedPassword);
                
                // invalidate their remember tokens since password changed
                $this->users->deleteRememberTokensForUser($userId);
            }

            $this->auditLogs->log(
                $authUser['id'] ?? null,
                'user_updated',
                'users',
                $userId,
                []
            );

            return ['status' => 'success', 'message' => 'User updated successfully.'];
        } catch (Throwable $exception) {
            Database::logError('User update failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to update user.'];
        }
    }

    public function delete(array $data, ?array $authUser): array
    {
        if (!$this->canManageUsers($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid user ID is required.'];
            }

            $userId = (int) $data['id'];

            if ($userId === (int) ($authUser['id'] ?? 0)) {
                return ['status' => 'error', 'message' => 'You cannot delete yourself.'];
            }

            if (!$this->users->findById($userId)) {
                return ['status' => 'error', 'message' => 'User not found.'];
            }

            $this->users->delete($userId);

            $this->auditLogs->log(
                $authUser['id'] ?? null,
                'user_deleted',
                'users',
                $userId,
                []
            );

            return ['status' => 'success', 'message' => 'User deleted successfully.'];
        } catch (Throwable $exception) {
            Database::logError('User deletion failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to delete user.'];
        }
    }
}
