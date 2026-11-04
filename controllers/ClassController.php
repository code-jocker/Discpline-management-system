<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/ClassModel.php';
require_once dirname(__DIR__) . '/models/Category.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';

final class ClassController
{
    private PDO $db;
    private User $users;
    private ClassModel $classes;
    private Category $categories;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->users = new User($this->db);
        $this->classes = new ClassModel($this->db);
        $this->categories = new Category($this->db);
        $this->auditLogs = new AuditLog($this->db);
    }

    public function canManageClasses(?array $authUser): bool
    {
        if (!$authUser || empty($authUser['id'])) {
            return false;
        }

        return $this->users->userHasPrivilegedRole((int) $authUser['id']);
    }

    public function fetch(array $params): array
    {
        try {
            $classes = $this->classes->all();

            $teacherIds = [];
            foreach ($classes as $class) {
                if (!empty($class['class_teacher_user_id'])) {
                    $teacherIds[] = (int) $class['class_teacher_user_id'];
                }
            }
            $teacherIds = array_values(array_unique($teacherIds));

            $teacherMap = [];
            if (!empty($teacherIds)) {
                $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
                $teacherStatement = $this->db->prepare(
                    "SELECT u.id, u.full_name
                     FROM users u
                     WHERE u.id IN ($placeholders)"
                );
                $teacherStatement->execute($teacherIds);
                foreach ($teacherStatement->fetchAll() as $teacher) {
                    $teacherMap[$teacher['id']] = $teacher['full_name'];
                }
            }

            // Add category names and assigned teacher names
            $categoryList = $this->categories->all();
            $categoryMap = [];
            foreach ($categoryList as $cat) {
                $categoryMap[$cat['id']] = $cat['name'];
            }
            
            foreach ($classes as &$cls) {
                $cls['category_name'] = $categoryMap[$cls['category_id']] ?? 'Unknown';
                $cls['class_teacher_name'] = $teacherMap[$cls['class_teacher_user_id']] ?? 'Unassigned';
            }

            return [
                'status' => 'success',
                'data' => [
                    'items' => $classes,
                ],
            ];
        } catch (Throwable $exception) {
            Database::logError('Class fetch failed', ['exception' => $exception->getMessage()]);
            return [
                'status' => 'error',
                'message' => 'Failed to fetch classes.',
            ];
        }
    }

    public function create(array $data, ?array $authUser): array
    {
        if (!$this->canManageClasses($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['name']) || empty($data['category_id'])) {
                return ['status' => 'error', 'message' => "Fields 'name' and 'category_id' are required."];
            }

            if (!$this->categories->exists((int)$data['category_id'])) {
                return ['status' => 'error', 'message' => 'Selected category does not exist.'];
            }

            if (isset($data['class_teacher_user_id']) && $data['class_teacher_user_id'] !== '') {
                if (!ctype_digit((string) $data['class_teacher_user_id'])) {
                    return ['status' => 'error', 'message' => 'Selected class teacher is invalid.'];
                }

                $teacher = $this->users->findById((int) $data['class_teacher_user_id']);
                if (!$teacher || stripos((string) ($teacher['role_name'] ?? ''), 'teacher') === false) {
                    return ['status' => 'error', 'message' => 'Selected class teacher must be a valid teacher account.'];
                }
            }

            $classId = $this->classes->create($data);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'class_created', 'Created class ID ' . $classId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return [
                'status' => 'success',
                'message' => 'Class created successfully.',
                'data' => ['id' => $classId],
            ];
        } catch (Throwable $exception) {
            Database::logError('Class creation failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to create class.'];
        }
    }

    public function update(array $data, ?array $authUser): array
    {
        if (!$this->canManageClasses($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid class ID is required.'];
            }
            if (empty($data['name']) || empty($data['category_id'])) {
                return ['status' => 'error', 'message' => "Fields 'name' and 'category_id' are required."];
            }

            $classId = (int) $data['id'];
            if (!$this->classes->exists($classId)) {
                return ['status' => 'error', 'message' => 'Class not found.'];
            }

            if (!$this->categories->exists((int)$data['category_id'])) {
                return ['status' => 'error', 'message' => 'Selected category does not exist.'];
            }

            if (isset($data['class_teacher_user_id']) && $data['class_teacher_user_id'] !== '') {
                if (!ctype_digit((string) $data['class_teacher_user_id'])) {
                    return ['status' => 'error', 'message' => 'Selected class teacher is invalid.'];
                }

                $teacher = $this->users->findById((int) $data['class_teacher_user_id']);
                if (!$teacher || stripos((string) ($teacher['role_name'] ?? ''), 'teacher') === false) {
                    return ['status' => 'error', 'message' => 'Selected class teacher must be a valid teacher account.'];
                }
            }

            $this->classes->update($classId, $data);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'class_updated', 'Updated class ID ' . $classId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return ['status' => 'success', 'message' => 'Class updated successfully.'];
        } catch (Throwable $exception) {
            Database::logError('Class update failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to update class.'];
        }
    }

    public function assignTeacher(array $data, ?array $authUser): array
    {
        if (!$this->canManageClasses($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid class ID is required.'];
            }

            $classId = (int) $data['id'];
            if (!$this->classes->exists($classId)) {
                return ['status' => 'error', 'message' => 'Class not found.'];
            }

            if (empty($data['class_teacher_user_id']) || !ctype_digit((string) $data['class_teacher_user_id'])) {
                return ['status' => 'error', 'message' => 'A valid teacher must be selected.'];
            }

            $teacherId = (int) $data['class_teacher_user_id'];
            $teacher = $this->users->findById($teacherId);
            if (!$teacher || stripos((string) ($teacher['role_name'] ?? ''), 'teacher') === false) {
                return ['status' => 'error', 'message' => 'Selected class teacher must be a valid teacher account.'];
            }

            $this->classes->assignTeacher($classId, $teacherId);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'class_teacher_assigned', 'Assigned teacher ID ' . $teacherId . ' to class ID ' . $classId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return ['status' => 'success', 'message' => 'Teacher assigned successfully.'];
        } catch (Throwable $exception) {
            Database::logError('Class teacher assignment failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to assign teacher to class.'];
        }
    }

    public function deleteAll(?array $authUser): array
    {
        if (!$this->canManageClasses($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            $classes = $this->classes->all();
            $deletedCount = 0;
            $errors = [];

            foreach ($classes as $class) {
                try {
                    $this->classes->delete((int) $class['id']);
                    $deletedCount++;
                } catch (Throwable $exception) {
                    $errors[] = sprintf(
                        'Class "%s" could not be deleted: %s',
                        $class['name'] ?? 'Unknown',
                        $exception->getMessage()
                    );
                }
            }

            if ($deletedCount === 0 && empty($errors)) {
                return ['status' => 'success', 'message' => 'No classes found to delete.'];
            }

            $message = sprintf('Deleted %d class(es).', $deletedCount);
            if (!empty($errors)) {
                $message .= ' Some classes could not be deleted.';
            }

            return [
                'status' => empty($errors) ? 'success' : 'partial',
                'message' => $message,
                'errors' => array_slice($errors, 0, 10),
                'deleted_count' => $deletedCount,
            ];
        } catch (Throwable $exception) {
            Database::logError('Class bulk deletion failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to delete classes.'];
        }
    }

    public function delete(array $data, ?array $authUser): array
    {
        if (!$this->canManageClasses($authUser)) {
            return ['status' => 'error', 'message' => 'Unauthorized.'];
        }

        try {
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return ['status' => 'error', 'message' => 'Valid class ID is required.'];
            }

            $classId = (int) $data['id'];
            if (!$this->classes->exists($classId)) {
                return ['status' => 'error', 'message' => 'Class not found.'];
            }

            $this->classes->delete($classId);

            if (isset($authUser['id'])) {
                $this->auditLogs->create((int)$authUser['id'], 'class_deleted', 'Deleted class ID ' . $classId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            }

            return ['status' => 'success', 'message' => 'Class deleted successfully.'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } catch (Throwable $exception) {
            Database::logError('Class deletion failed', ['exception' => $exception->getMessage()]);
            return ['status' => 'error', 'message' => 'Failed to delete class.'];
        }
    }
}
