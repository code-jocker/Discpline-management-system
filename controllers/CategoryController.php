<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Category.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';

final class CategoryController
{
    private PDO $db;
    private User $users;
    private Category $categories;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->users = new User($this->db);
        $this->categories = new Category($this->db);
        $this->auditLogs = new AuditLog($this->db);
    }

    public function canManageCategories(?array $authUser): bool
    {
        if (!$authUser || empty($authUser['id'])) {
            return false;
        }

        return $this->users->userHasPrivilegedRole((int) $authUser['id']);
    }

    public function fetch(array $params): array
    {
        try {
            $categories = $this->categories->all();
            return [
                'status' => 'success',
                'data' => [
                    'items' => $categories,
                ],
            ];
        } catch (Throwable $exception) {
            Database::logError('Category fetch failed', ['exception' => $exception->getMessage()]);
            return [
                'status' => 'error',
                'message' => 'Failed to fetch categories.',
            ];
        }
    }

    public function create(array $data, ?array $authUser): array
    {
        if (!$this->canManageCategories($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['name'])) {
                return ['status' => 'error', 'message' => "Field 'name' is required."];
            }

            $categoryId = $this->categories->create($data);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'category_created', 'Created category ID ' . $categoryId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return [
                'status' => 'success',
                'message' => 'Category created successfully.',
                'data' => ['id' => $categoryId],
            ];
        } catch (Throwable $exception) {
            Database::logError('Category creation failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to create category.'];
        }
    }

    public function update(array $data, ?array $authUser): array
    {
        if (!$this->canManageCategories($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid category ID is required.'];
            }
            if (empty($data['name'])) {
                return ['status' => 'error', 'message' => "Field 'name' is required."];
            }

            $categoryId = (int) $data['id'];
            if (!$this->categories->exists($categoryId)) {
                return ['status' => 'error', 'message' => 'Category not found.'];
            }

            $this->categories->update($categoryId, $data);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'category_updated', 'Updated category ID ' . $categoryId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return ['status' => 'success', 'message' => 'Category updated successfully.'];
        } catch (Throwable $exception) {
            Database::logError('Category update failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to update category.'];
        }
    }

    public function delete(array $data, ?array $authUser): array
    {
        if (!$this->canManageCategories($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid category ID is required.'];
            }

            $categoryId = (int) $data['id'];
            if (!$this->categories->exists($categoryId)) {
                return ['status' => 'error', 'message' => 'Category not found.'];
            }

            $this->categories->delete($categoryId);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'category_deleted', 'Deleted category ID ' . $categoryId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return ['status' => 'success', 'message' => 'Category deleted successfully.'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } catch (Throwable $exception) {
            Database::logError('Category deletion failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to delete category.'];
        }
    }
}
