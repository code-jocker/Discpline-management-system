<?php
declare(strict_types=1);

use App\Repositories\StudentRepository;

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Student.php';
require_once dirname(__DIR__) . '/models/Category.php';
require_once dirname(__DIR__) . '/models/ClassModel.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';
require_once dirname(__DIR__) . '/app/Repositories/StudentRepository.php';
require_once dirname(__DIR__) . '/app/Services/FileStorageService.php';

use App\Services\FileStorageService;

final class StudentController
{
    private PDO $db;
    private User $users;
    private Student $students;
    private Category $categories;
    private ClassModel $classes;
    private AuditLog $auditLogs;
    private StudentRepository $studentRepository;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->users = new User($this->db);
        $this->students = new Student($this->db);
        $this->categories = new Category($this->db);
        $this->classes = new ClassModel($this->db);
        $this->auditLogs = new AuditLog($this->db);
        $this->studentRepository = new StudentRepository($this->db);
    }

    public function indexData(): array
    {
        try {
            $teachers = [];
            $teacherStatement = $this->db->prepare(
                'SELECT u.id, u.full_name
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE LOWER(r.name) LIKE :teacher_pattern
                 ORDER BY u.full_name ASC'
            );
            $teacherStatement->execute([
                'teacher_pattern' => '%teacher%'
            ]);
            $teachers = $teacherStatement->fetchAll();

            return [
                'categories' => $this->categories->all(),
                'classes' => $this->classes->all(),
                'teachers' => $teachers,
            ];
        } catch (Throwable $exception) {
            Database::logError('Student index data load failed', ['exception' => $exception->getMessage()]);
            return [
                'categories' => [],
                'classes' => [],
                'teachers' => [],
            ];
        }
    }

    public function canManageStudents(?array $authUser): bool
    {
        if (!$authUser || empty($authUser['id'])) {
            return false;
        }

        return $this->users->userHasPrivilegedRole((int) $authUser['id']);
    }

    /**
     * Validate the ID-card profile fields and attach an uploaded portrait.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $files
     * @return array{0: array<string, mixed>, 1: ?string} normalized payload and error message
     */
    private function withProfile(array $data, ?array $files, ?array $authUser, ?string $currentPhoto = null): array
    {
        $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
        if ($dateOfBirth !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirth);
            if (!$parsed || $parsed->format('Y-m-d') !== $dateOfBirth) {
                return [$data, 'Date of birth must be a valid date.'];
            }
            if ($parsed > new \DateTimeImmutable('today')) {
                return [$data, 'Date of birth cannot be in the future.'];
            }
            $data['date_of_birth'] = $dateOfBirth;
        } else {
            $data['date_of_birth'] = null;
        }

        $address = trim((string) ($data['address'] ?? ''));
        if (mb_strlen($address) > 255) {
            return [$data, 'Address must be 255 characters or less.'];
        }
        $data['address'] = $address === '' ? null : $address;

        $photo = $currentPhoto;
        if (!empty($data['remove_photo'])) {
            $photo = null;
        }

        if ($files !== null && !empty($files['photo_file']['name'])) {
            if (($files['photo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return [$data, 'The portrait could not be uploaded.'];
            }

            $mime = mime_content_type((string) $files['photo_file']['tmp_name']) ?: '';
            if (!str_starts_with((string) $mime, 'image/')) {
                return [$data, 'Portrait must be an image file.'];
            }

            try {
                $stored = (new FileStorageService())->store(
                    $files['photo_file'],
                    (int) ($_SESSION['tenant_id'] ?? 1),
                    isset($authUser['id']) ? (int) $authUser['id'] : null
                );
                $photo = $stored['storage_path'];
            } catch (Throwable $exception) {
                Database::logError('Student portrait upload failed', ['exception' => $exception->getMessage()]);
                return [$data, $exception->getMessage()];
            }
        }

        $data['photo_path'] = $photo;

        return [$data, null];
    }

    public function fetch(array $params): array
    {
        try {
            $filters = [
                'class_id' => isset($params['class_id']) && ctype_digit((string) $params['class_id'])
                    ? (int) $params['class_id']
                    : null,
                'category_id' => isset($params['category_id']) && ctype_digit((string) $params['category_id'])
                    ? (int) $params['category_id']
                    : null,
                'search' => isset($params['search']) ? trim($params['search']) : null,
            ];

            $page = max(1, (int) ($params['page'] ?? 1));
            $limit = max(1, min((int) ($params['limit'] ?? 10000), 10000));
            $offset = ($page - 1) * $limit;

            $students = $this->studentRepository->paginate($filters, $limit, $offset);
            $total = $this->studentRepository->count($filters);

            return [
                'status' => 'success',
                'data' => [
                    'items' => $students,
                    'meta' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => max(1, (int) ceil($total / $limit)),
                    ],
                ],
            ];
        } catch (Throwable $exception) {
            Database::logError('Student fetch failed', ['exception' => $exception->getMessage(), 'params' => $params]);
            return [
                'status' => 'error',
                'message' => 'Failed to fetch students.',
            ];
        }
    }

    public function create(array $data, ?array $authUser, ?array $files = []): array
    {
        if (!$this->canManageStudents($authUser)) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized to create students.',
            ];
        }

        try {
            // Validate required fields
            $requiredFields = ['full_name', 'class_id', 'category_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'status' => 'error',
                        'message' => "Field '{$field}' is required.",
                    ];
                }
            }

            // Validate class and category exist
            if (!$this->classes->exists((int) $data['class_id'])) {
                return [
                    'status' => 'error',
                    'message' => 'Selected class does not exist.',
                ];
            }

            if (!$this->categories->exists((int) $data['category_id'])) {
                return [
                    'status' => 'error',
                    'message' => 'Selected category does not exist.',
                ];
            }

            if ($this->students->existsByNameAndClass($data['full_name'], (int) $data['class_id'])) {
                return [
                    'status' => 'error',
                    'message' => 'A student with this exact name already exists in the selected class.',
                ];
            }

            if (isset($data['student_code'])) {
                unset($data['student_code']);
            }

            [$data, $profileError] = $this->withProfile($data, $files, $authUser);
            if ($profileError !== null) {
                return [
                    'status' => 'error',
                    'message' => $profileError,
                ];
            }

            $studentId = $this->students->create($data);

            // Log the action
            $this->auditLogs->log(
                $authUser['id'] ?? null,
                'student_created',
                'students',
                $studentId,
                ['data' => $data]
            );

            return [
                'status' => 'success',
                'message' => 'Student created successfully.',
                'data' => ['id' => $studentId],
            ];
        } catch (Throwable $exception) {
            Database::logError('Student creation failed', ['exception' => $exception->getMessage(), 'data' => $data]);
            return [
                'status' => 'error',
                'message' => 'Failed to create student.',
            ];
        }
    }

    public function update(array $data, ?array $authUser, ?array $files = []): array
    {
        if (!$this->canManageStudents($authUser)) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized to update students.',
            ];
        }

        try {
            // Validate required fields
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return [
                    'status' => 'error',
                    'message' => 'Valid student ID is required.',
                ];
            }

            $studentId = (int) $data['id'];

            // Check if student exists
            if (!$this->students->exists($studentId)) {
                return [
                    'status' => 'error',
                    'message' => 'Student not found.',
                ];
            }

            foreach (['full_name', 'class_id', 'category_id'] as $field) {
                if (empty($data[$field])) {
                    return [
                        'status' => 'error',
                        'message' => "Field '{$field}' is required.",
                    ];
                }
            }

            foreach (['class_id', 'category_id'] as $field) {
                if (!ctype_digit((string) $data[$field])) {
                    return [
                        'status' => 'error',
                        'message' => "Field '{$field}' is required and must be a valid selection.",
                    ];
                }
            }

            $targetClassId = (int) $data['class_id'];
            $targetCategoryId = (int) $data['category_id'];

            if (!$this->classes->exists($targetClassId)) {
                return [
                    'status' => 'error',
                    'message' => 'Selected class does not exist.',
                ];
            }

            if (!$this->categories->exists($targetCategoryId)) {
                return [
                    'status' => 'error',
                    'message' => 'Selected category does not exist.',
                ];
            }

            $existing = $this->students->find($studentId);
            if (!$existing) {
                return [
                    'status' => 'error',
                    'message' => 'Student not found.',
                ];
            }

            if (isset($data['student_code'])) {
                unset($data['student_code']);
            }

            $checkName = $data['full_name'] ?? null;
            $checkClassId = isset($data['class_id']) ? (int) $data['class_id'] : null;

            if ($checkName || $checkClassId) {
                // If only partial data provided, we might need to fetch existing to check properly,
                // but let's assume full payload is provided on update.
                $existing = $this->students->find($studentId);
                $finalName = $checkName ?: $existing['full_name'];
                $finalClassId = $checkClassId ?: (int) $existing['class_id'];

                if ($this->students->existsByNameAndClass($finalName, $finalClassId, $studentId)) {
                    return [
                        'status' => 'error',
                        'message' => 'Another student with this exact name already exists in that class.',
                    ];
                }
            }

            [$data, $profileError] = $this->withProfile($data, $files, $authUser, $existing['photo_path'] ?? null);
            if ($profileError !== null) {
                return [
                    'status' => 'error',
                    'message' => $profileError,
                ];
            }

            $this->students->update($studentId, $data);

            // Log the action
            $this->auditLogs->log(
                $authUser['id'] ?? null,
                'student_updated',
                'students',
                $studentId,
                ['data' => $data]
            );

            return [
                'status' => 'success',
                'message' => 'Student updated successfully.',
            ];
        } catch (Throwable $exception) {
            Database::logError('Student update failed', ['exception' => $exception->getMessage(), 'data' => $data]);
            return [
                'status' => 'error',
                'message' => 'Failed to update student.',
            ];
        }
    }

    public function delete(array $data, ?array $authUser): array
    {
        if (!$this->canManageStudents($authUser)) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized to delete students.',
            ];
        }

        try {
            // Validate required fields
            if (empty($data['id']) || !ctype_digit((string) $data['id'])) {
                return [
                    'status' => 'error',
                    'message' => 'Valid student ID is required.',
                ];
            }

            $studentId = (int) $data['id'];

            // Check if student exists
            if (!$this->students->exists($studentId)) {
                return [
                    'status' => 'error',
                    'message' => 'Student not found.',
                ];
            }

            $this->students->delete($studentId);

            // Log the action
            $this->auditLogs->log(
                $authUser['id'] ?? null,
                'student_deleted',
                'students',
                $studentId,
                ['data' => $data]
            );

            return [
                'status' => 'success',
                'message' => 'Student deleted successfully.',
            ];
        } catch (Throwable $exception) {
            Database::logError('Student deletion failed', ['exception' => $exception->getMessage(), 'data' => $data]);
            return [
                'status' => 'error',
                'message' => 'Failed to delete student.',
            ];
        }
    }
}
