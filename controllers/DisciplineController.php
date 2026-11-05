<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php'; // Ensure Database is available
require_once dirname(__DIR__) . '/auth/session.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Student.php';
require_once dirname(__DIR__) . '/models/ClassModel.php';
require_once dirname(__DIR__) . '/models/Deduction.php';
require_once dirname(__DIR__) . '/models/Notification.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';
require_once dirname(__DIR__) . '/models/Settings.php';
require_once dirname(__DIR__) . '/app/Services/RiskEngineService.php';
require_once dirname(__DIR__) . '/app/Services/FileStorageService.php';
require_once dirname(__DIR__) . '/app/Services/LetterService.php';
require_once dirname(__DIR__) . '/app/Services/ParentNotificationService.php';

use App\Services\FileStorageService;
use App\Services\RiskEngineService;

final class DisciplineController
{
    private PDO $db;
    private User $users;
    private Student $students;
    private ClassModel $classes;
    private Deduction $deductions;
    private Notification $notifications;
    private AuditLog $auditLogs;
    private array $fallbackRoles;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->users = new User($this->db);
        $this->students = new Student($this->db);
        $this->classes = new ClassModel($this->db);
        $this->deductions = new Deduction($this->db);
        $this->notifications = new Notification($this->db);
        $this->auditLogs = new AuditLog($this->db);
        $this->fallbackRoles = $this->resolveFallbackRoles();
    }

    public function pageData(?array $authUser, bool $withStudents = true): array
    {
        try {
            return [
                'status' => 'success',
                'students' => $withStudents ? $this->students->listForSelection() : [],
                'classes' => $this->classes->all(),
                'can_deduct' => $this->canDeductMarks($authUser),
            ];
        } catch (Throwable $exception) {
            Database::logError('Discipline page data load failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not load the discipline engine.', 500);
        }
    }

    public function deduct(array $input, ?array $authUser): array
    {
        if (!$this->canDeductMarks($authUser)) {
            return $this->error('You do not have permission to deduct marks.', 403);
        }

        $applyToClass = isset($input['apply_to_class']) && in_array(strtolower((string) ($input['apply_to_class'] ?? '')), ['1', 'true', 'on', 'yes', 'checked'], true);
        $term = $this->sanitizeRequiredInt($input['term'] ?? null, 'Select a valid term.');
        $actionType = strtolower($input['action_type'] ?? 'deduct');
        if (!in_array($actionType, ['deduct', 'add', 'set'], true)) {
            return $this->error('Invalid action type.', 422);
        }

        $marksInputKey = $actionType === 'set'
            ? (isset($input['marks_to_set']) ? 'marks_to_set' : 'marks_to_deduct')
            : 'marks_to_deduct';
        $marksToDeduct = $this->sanitizeRequiredInt($input[$marksInputKey] ?? null, 'Enter valid marks.');
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($term['status'] === 'error') return $term;
        if ($marksToDeduct['status'] === 'error') return $marksToDeduct;

        if (!in_array($term['value'], [1, 2, 3], true)) {
            return $this->error('Term must be 1, 2, or 3.', 422);
        }

        $performedBy = (int) ($authUser['id'] ?? 0);
        if ($performedBy <= 0) {
            return $this->error('Unable to identify the authenticated user.', 401);
        }

        $settings = new \Settings($this->db);
        $maxMarksPerTerm = (int) $settings->get('max_marks_per_term', '40');
        $maxMarksPerTerm = max(1, min(40, $maxMarksPerTerm));

        if ($marksToDeduct['value'] < 0) {
            return $this->error('Marks value cannot be negative.', 422);
        }

        if ($actionType !== 'set' && $marksToDeduct['value'] <= 0) {
            return $this->error('Marks value must be greater than zero.', 422);
        }

        if ($actionType === 'set' && $marksToDeduct['value'] > $maxMarksPerTerm) {
            return $this->error(sprintf('Marks cannot exceed %d.', $maxMarksPerTerm), 422);
        }

        if ($actionType !== 'set' && ($reason === '' || mb_strlen($reason) < 5 || mb_strlen($reason) > 500)) {
            return $this->error('Reason must be between 5 and 500 characters.', 422);
        }

        $actualMarksDelta = $actionType === 'add'
            ? -abs($marksToDeduct['value'])
            : abs($marksToDeduct['value']);

        try {
            if (!Database::columnExists('deductions_history', 'evidence_file_id')) {
                $this->db->exec('ALTER TABLE deductions_history ADD COLUMN evidence_file_id INT UNSIGNED DEFAULT NULL');
            }

            if (!Database::columnExists('deductions_history', 'incident_category')) {
                $this->db->exec('ALTER TABLE deductions_history ADD COLUMN incident_category VARCHAR(100) DEFAULT NULL');
            }

            $incidentCategory = trim((string) ($input['incident_category'] ?? ''));
            if ($incidentCategory !== '' && mb_strlen($incidentCategory) > 100) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->error('Incident category must be 100 characters or less.', 422);
            }

            $evidenceFileId = null;
            if (!empty($_FILES['evidence_file']) && is_array($_FILES['evidence_file']) && (int) ($_FILES['evidence_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try {
                    $storage = new FileStorageService();
                    $result = $storage->store($_FILES['evidence_file'], (int) ($_SESSION['tenant_id'] ?? 1), $performedBy);
                    $evidenceFileId = (int) $result['id'];
                } catch (Throwable $uploadException) {
                    Database::logError('Evidence upload failed', ['exception' => $uploadException->getMessage()]);
                    return $this->error('Evidence file could not be uploaded.', 422);
                }
            }

            $this->db->beginTransaction();

            if ($applyToClass) {
                $classId = $this->sanitizeRequiredInt($input['class_id'] ?? null, 'Select a valid class.');
                if ($classId['status'] === 'error') {
                    $this->db->rollBack();
                    return $classId;
                }

                $stmtStudents = $this->db->prepare('SELECT id FROM students WHERE class_id = :class_id ORDER BY full_name ASC');
                $stmtStudents->execute(['class_id' => $classId['value']]);
                $studentIds = array_map('intval', $stmtStudents->fetchAll(PDO::FETCH_COLUMN));

                if ($studentIds === []) {
                    $this->db->rollBack();
                    return $this->error('No students found for the selected class.', 404);
                }

                foreach ($studentIds as $studentIdValue) {
                    $snapshot = $this->deductions->getStudentTermSnapshot($studentIdValue, $term['value'], true);
                    if (!$snapshot) {
                        $this->db->rollBack();
                        return $this->error('One or more student records are missing marks data.', 404);
                    }

                    if ($actionType === 'add') {
                        $currentMarks = (int) $snapshot['current_marks'];
                        if (($currentMarks + $marksToDeduct['value']) > $maxMarksPerTerm) {
                            $this->db->rollBack();
                            return $this->error(sprintf('Marks cannot exceed %d for one or more students.', $maxMarksPerTerm), 422);
                        }
                    }
                }

                $processedStudentCount = 0;
                $pendingParentNotices = [];
                foreach ($studentIds as $studentIdValue) {
                    $snapshot = $this->deductions->getStudentTermSnapshot($studentIdValue, $term['value'], true);
                    if (!$snapshot) {
                        $this->db->rollBack();
                        return $this->error('One or more student records are missing marks data.', 404);
                    }

                    if ($actionType === 'set') {
                        $this->deductions->setMarks($studentIdValue, $term['value'], $marksToDeduct['value']);

                        try {
                            $riskService = new RiskEngineService($this->db);
                            $riskService->assessStudent($studentIdValue, $term['value']);
                        } catch (Throwable $riskException) {
                            Database::logError('Risk reassessment failed after class manual marks update', [
                                'student_id' => $studentIdValue,
                                'exception' => $riskException->getMessage(),
                            ]);
                        }

                        $processedStudentCount++;
                        continue;
                    }

                    $currentMarks = (int) $snapshot['current_marks'];
                    $effectiveMarksForStudent = $actionType === 'deduct'
                        ? min($marksToDeduct['value'], $currentMarks)
                        : min($marksToDeduct['value'], $maxMarksPerTerm - $currentMarks);

                    if ($effectiveMarksForStudent <= 0) {
                        continue;
                    }

                    $actualMarksDelta = $actionType === 'add'
                        ? -abs($effectiveMarksForStudent)
                        : abs($effectiveMarksForStudent);

                    $historyId = $this->deductions->insertHistory(
                        $studentIdValue,
                        $term['value'],
                        $actualMarksDelta,
                        $reason,
                        $performedBy,
                        $evidenceFileId,
                        $incidentCategory !== '' ? $incidentCategory : null
                    );
                    $this->deductions->applyMarksDeduction($studentIdValue, $term['value'], $actualMarksDelta);

                    if ($actionType !== 'set') {
                        $pendingParentNotices[] = [
                            'student_id' => (int) $studentIdValue,
                            'deduction_id' => $historyId,
                            'marks' => (int) $effectiveMarksForStudent,
                            'reason' => $reason,
                            'action' => $actionType,
                        ];
                    }

                    try {
                        $riskService = new RiskEngineService($this->db);
                        $riskService->assessStudent($studentIdValue, $term['value']);
                    } catch (Throwable $riskException) {
                        Database::logError('Risk reassessment failed after class discipline action', [
                            'student_id' => $studentIdValue,
                            'exception' => $riskException->getMessage(),
                        ]);
                    }

                    $notificationMessage = sprintf(
                        '%s %s %d marks in term %d. Reason: %s',
                        $snapshot['full_name'],
                        $actionType === 'add' ? 'was rewarded/added' : 'lost',
                        $effectiveMarksForStudent,
                        $term['value'],
                        $reason
                    );
                    $recipientIds = $this->notifications->resolveDeductionRecipients($snapshot);
                    $this->notifications->createBulk(
                        $recipientIds,
                        $notificationMessage,
                        Notification::PRIORITY_MEDIUM,
                        (int) ($classId['value'] ?? 0)
                    );
                    $processedStudentCount++;
                }

                $auditActionWord = match ($actionType) {
                    'add' => 'Added/Rewarded',
                    'set' => 'Set',
                    default => 'Deducted'
                };
                $this->auditLogs->create(
                    $performedBy,
                    'discipline_action_class',
                    sprintf('%s %d marks for %d students in class ID %d for term %d%s', $auditActionWord, $marksToDeduct['value'], $processedStudentCount, $classId['value'], $term['value'], $actionType === 'set' ? '' : sprintf('. Reason: %s', $reason)),
                    client_ip_address()
                );

                $this->db->commit();

                $this->notifyParentsAfterMarksChange($pendingParentNotices, $term['value']);

                return [
                    'status' => 'success',
                    'message' => $actionType === 'set'
                        ? 'Manual marks updated successfully for the selected class.'
                        : 'Marks updated successfully for the selected class.',
                    'updated_count' => $processedStudentCount,
                    'data' => [
                        'class_id' => $classId['value'],
                        'term' => $term['value'],
                        'updated_count' => $processedStudentCount,
                    ],
                ];
            }

            $studentId = $this->sanitizeRequiredInt($input['student_id'] ?? null, 'Select a valid student.');
            if ($studentId['status'] === 'error') {
                $this->db->rollBack();
                return $studentId;
            }

            $snapshot = $this->deductions->getStudentTermSnapshot($studentId['value'], $term['value'], true);
            if (!$snapshot) {
                if (!$this->students->exists($studentId['value'])) {
                    $this->db->rollBack();
                    return $this->error('Selected student could not be found.', 404);
                }

                $this->db->rollBack();
                return $this->error('Selected student has no marks record for the chosen term.', 404);
            }

            if ($actionType === 'set') {
                $this->deductions->setMarks($studentId['value'], $term['value'], $marksToDeduct['value']);

                try {
                    $riskService = new RiskEngineService($this->db);
                    $riskService->assessStudent($studentId['value'], $term['value']);
                } catch (Throwable $riskException) {
                    Database::logError('Risk reassessment failed after manual marks update', [
                        'student_id' => $studentId['value'],
                        'exception' => $riskException->getMessage(),
                    ]);
                }

                $this->auditLogs->create(
                    $performedBy,
                    'discipline_action',
                    sprintf('Set marks to %d for %s (%s) for term %d.', $marksToDeduct['value'], $snapshot['full_name'], $snapshot['student_code'], $term['value']),
                    client_ip_address()
                );

                $this->db->commit();

                return [
                    'status' => 'success',
                    'message' => 'Marks set successfully.',
                    'updated_marks' => $marksToDeduct['value'],
                    'data' => [
                        'student_id' => $studentId['value'],
                        'term' => $term['value'],
                        'updated_marks' => $marksToDeduct['value'],
                        'student_name' => $snapshot['full_name'],
                        'student_code' => $snapshot['student_code'],
                    ],
                ];
            }

            $currentMarks = (int) $snapshot['current_marks'];
            if ($actionType === 'add' && ($currentMarks + $marksToDeduct['value']) > $maxMarksPerTerm) {
                $this->db->rollBack();
                return $this->error(sprintf('Marks cannot exceed %d.', $maxMarksPerTerm), 422);
            }

            $effectiveMarksForStudent = $actionType === 'deduct'
                ? min($marksToDeduct['value'], $currentMarks)
                : min($marksToDeduct['value'], $maxMarksPerTerm - $currentMarks);

            if ($actionType === 'deduct' && $effectiveMarksForStudent <= 0) {
                $this->db->rollBack();
                return $this->error('This student has no marks available to deduct.', 422);
            }

            $actualMarksDelta = $actionType === 'add'
                ? -abs($effectiveMarksForStudent)
                : abs($effectiveMarksForStudent);

            $historyId = $this->deductions->insertHistory(
                $studentId['value'],
                $term['value'],
                $actualMarksDelta,
                $reason,
                $performedBy,
                $evidenceFileId,
                $incidentCategory !== '' ? $incidentCategory : null
            );
            $this->deductions->applyMarksDeduction($studentId['value'], $term['value'], $actualMarksDelta);

            try {
                $riskService = new RiskEngineService($this->db);
                $riskService->assessStudent($studentId['value'], $term['value']);
            } catch (Throwable $riskException) {
                Database::logError('Risk reassessment failed after discipline action', [
                    'student_id' => $studentId['value'],
                    'exception' => $riskException->getMessage(),
                ]);
            }

            $updatedMarks = max(0, min($maxMarksPerTerm, $currentMarks - $actualMarksDelta));
            $actionWord = $actionType === 'add' ? 'rewarded/added' : 'lost';
            $notificationMessage = sprintf(
                '%s %s %d marks in term %d. Reason: %s',
                $snapshot['full_name'],
                $actionWord,
                $effectiveMarksForStudent,
                $term['value'],
                $reason
            );
            $recipientIds = $this->notifications->resolveDeductionRecipients($snapshot);
            $this->notifications->createBulk(
                $recipientIds,
                $notificationMessage,
                Notification::PRIORITY_MEDIUM,
                (int) ($snapshot['class_id'] ?? 0) ?: null
            );

            $auditActionWord = $actionType === 'add' ? 'Added/Rewarded' : 'Deducted';
            $auditDescription = sprintf(
                '%s %d marks to/from %s (%s) for term %d. Reason: %s',
                $auditActionWord,
                $effectiveMarksForStudent,
                $snapshot['full_name'],
                $snapshot['student_code'],
                $term['value'],
                $reason
            );
            $this->auditLogs->create($performedBy, 'discipline_action', $auditDescription, client_ip_address());

            $this->db->commit();
            $this->syncLowMarksLetter($studentId['value'], $term['value'], $updatedMarks, $reason);
            $this->notifyParentsAfterMarksChange([[
                'student_id' => (int) $studentId['value'],
                'deduction_id' => $historyId,
                'marks' => (int) $effectiveMarksForStudent,
                'reason' => $reason,
                'action' => $actionType,
            ]], $term['value']);

            return [
                'status' => 'success',
                'message' => 'Marks updated successfully.',
                'updated_marks' => $updatedMarks,
                'data' => [
                    'student_id' => $studentId['value'],
                    'term' => $term['value'],
                    'updated_marks' => $updatedMarks,
                    'student_name' => $snapshot['full_name'],
                    'student_code' => $snapshot['student_code'],
                ],
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Database::logError('Marks action failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not process the action right now.', 500);
        }
    }

    public function history(array $query, ?array $authUser): array
    {
        if (!$this->canDeductMarks($authUser)) {
            return $this->error('You do not have permission to view discipline history.', 403);
        }

        $filters = [
            'student_id' => $this->sanitizeOptionalInt($query['student_id'] ?? null),
            'class_id' => $this->sanitizeOptionalInt($query['class_id'] ?? null),
            'term' => $this->sanitizeOptionalInt($query['term'] ?? null),
        ];

        if ($filters['term'] === null) {
            $settings = new Settings($this->db);
            $filters['term'] = (int) $settings->get('current_term', 1);
        }

        if (!in_array($filters['term'], [1, 2, 3], true)) {
            return $this->error('Term filter must be 1, 2, or 3.', 422);
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min((int) ($query['limit'] ?? 10), 10000));
        $offset = ($page - 1) * $limit;
        $sortBy = (($query['sort_by'] ?? 'latest') === 'most_deducted') ? 'most_deducted' : 'latest';

        try {
            $history = $this->deductions->fetchHistory($filters, $limit, $offset, $sortBy);
            $total = $this->deductions->countHistory($filters);
        } catch (Throwable $exception) {
            Database::logError('Discipline history fetch failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not load the deduction history.', 500);
        }

        return [
            'status' => 'success',
            'data' => $history,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $limit)),
                'can_delete' => $this->canManageHistory($authUser),
            ],
        ];
    }

    public function deleteHistory(array $input, ?array $authUser): array
    {
        if (!$this->canManageHistory($authUser)) {
            return $this->error('You do not have permission to delete deduction history.', 403);
        }

        $action = strtolower((string) ($input['action'] ?? ''));

        try {
            if ($action === 'delete_one') {
                $historyId = $this->sanitizeRequiredInt($input['id'] ?? null, 'Select a valid history entry.');
                if ($historyId['status'] === 'error') {
                    return $historyId;
                }

                $deleted = $this->deductions->deleteHistoryEntry((int) $historyId['value']);
                if (!$deleted) {
                    return $this->error('Unable to delete that deduction entry.', 500);
                }

                $this->auditLogs->create((int) $authUser['id'], 'deduction_history_delete', 'Deleted deduction history entry ID ' . $historyId['value'], client_ip_address());

                return ['status' => 'success', 'message' => 'Deduction entry deleted successfully.'];
            }

            if ($action === 'delete_all') {
                $filters = [
                    'student_id' => $this->sanitizeOptionalInt($input['student_id'] ?? null),
                    'class_id' => $this->sanitizeOptionalInt($input['class_id'] ?? null),
                    'term' => $this->sanitizeOptionalInt($input['term'] ?? null),
                ];

                $deletedCount = $this->deductions->deleteHistoryByFilters($filters);
                $this->auditLogs->create((int) $authUser['id'], 'deduction_history_delete_all', 'Deleted ' . $deletedCount . ' deduction history entries.', client_ip_address());

                return ['status' => 'success', 'message' => 'Deduction history entries deleted successfully.', 'deleted_count' => $deletedCount];
            }

            return $this->error('Invalid history delete action.', 422);
        } catch (Throwable $exception) {
            Database::logError('Deduction history delete failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not delete the deduction history right now.', 500);
        }
    }

    public function unreadNotifications(?array $authUser): array
    {
        if (!$authUser || empty($authUser['id'])) {
            return $this->error('Authentication required.', 401);
        }

        try {
            $notifications = $this->notifications->fetchUnread((int) $authUser['id']);
        } catch (Throwable $exception) {
            Database::logError('Notification fetch failed', ['exception' => $exception->getMessage()]);
            return $this->error('We could not load notifications right now.', 500);
        }

        return [
            'status' => 'success',
            'data' => $notifications,
        ];
    }

    public function canDeductMarks(?array $authUser): bool
    {
        if (!$authUser || empty($authUser['id'])) {
            return false;
        }

        $roleName = strtolower((string) ($authUser['role_name'] ?? ''));
        if ($this->users->userHasPrivilegedRole((int) $authUser['id'])) {
            return true;
        }

        if (stripos($roleName, 'discipline') !== false) {
            return true;
        }

        if (stripos($roleName, 'teacher') !== false) {
            return true;
        }

        return false;
    }

    private function sanitizeRequiredInt($value, string $message): array
    {
        if ($value === null || $value === '' || !ctype_digit((string) $value)) {
            return $this->error($message, 422);
        }

        return [
            'status' => 'success',
            'value' => (int) $value,
        ];
    }

    private function sanitizeOptionalInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) ? (int) $value : null;
    }

    private function resolveFallbackRoles(): array
    {
        $configured = getenv('SBMS_DEDUCT_MARKS_ROLES') ?: 'teacher,admin,headteacher,deputy headteacher discipline';
        $roles = array_filter(array_map('trim', explode(',', strtolower($configured))));

        return $roles ?: ['teacher', 'admin', 'headteacher', 'deputy headteacher discipline'];
    }

    private function canManageHistory(?array $authUser): bool
    {
        if (!$authUser || empty($authUser['id'])) {
            return false;
        }

        $roleName = strtolower((string) ($authUser['role_name'] ?? ''));
        return in_array($roleName, ['admin', 'headteacher'], true)
            || stripos($roleName, 'discipline') !== false;
    }

    private function error(string $message, int $code): array
    {
        http_response_code($code);

        return [
            'status' => 'error',
            'message' => $message,
        ];
    }

    /**
     * Fan-out to parent channels happens after the DB commit so a missing email
     * gateway or WhatsApp worker can never roll back a saved discipline action.
     *
     * @param array<int,array{student_id:int,deduction_id:int,marks:int,reason:string,action:string}> $notices
     */
    private function notifyParentsAfterMarksChange(array $notices, int $term): void
    {
        if ($notices === []) {
            return;
        }

        try {
            $service = new \App\Services\ParentNotificationService($this->db);
            foreach ($notices as $notice) {
                if ((int) $notice['marks'] <= 0) {
                    continue;
                }

                if ($notice['action'] === 'add') {
                    $service->notifyParentsOfAchievement(
                        (int) $notice['student_id'],
                        sprintf('Marks rewarded in term %d', $term)
                    );
                    continue;
                }

                $service->notifyParentsOfIncident(
                    (int) $notice['student_id'],
                    (int) $notice['deduction_id'],
                    (string) $notice['reason'],
                    (int) $notice['marks']
                );
            }
        } catch (Throwable $exception) {
            Database::logError('Parent notification fan-out failed', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function syncLowMarksLetter(int $studentId, int $term, int $updatedMarks, string $reason = ''): void
    {
        try {
            $settings = new Settings($this->db);
            if ((string) $settings->get('low_marks_letter_enabled', '1') !== '1') {
                return;
            }

            $threshold = max(0, min(40, (int) $settings->get('low_marks_threshold', '20')));
            if ($updatedMarks >= $threshold) {
                return;
            }

            $letterService = new \App\Services\LetterService($this->db);
            $letter = $letterService->generateParentInvitationLetter($studentId, $term, $updatedMarks, $threshold, $reason);
            if ($letter === null) {
                return;
            }

            $notificationService = new \App\Services\ParentNotificationService($this->db);
            $notificationService->notifyParentsOfLetter(
                $studentId,
                (string) ($letter['subject'] ?? 'Parent Invitation Letter'),
                (string) ($letter['body'] ?? '')
            );
        } catch (Throwable $exception) {
            Database::logError('Auto letter sync failed', [
                'student_id' => $studentId,
                'term' => $term,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
