<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\DisciplineRepository;
use App\Repositories\UserRepository;
use App\Services\RiskEngineService;
use AuditLog;
use Database;
use Notification;
use PDO;
use RuntimeException;

require_once __DIR__ . '/LetterService.php';
require_once __DIR__ . '/ParentNotificationService.php';
require_once dirname(__DIR__, 2) . '/models/Settings.php';

final class DisciplineService
{
    private Notification $notifications;
    private AuditLog $auditLogs;
    private PDO $db;
    private DisciplineRepository $repository;
    private UserRepository $users;

    public function __construct(PDO $db, DisciplineRepository $repository, UserRepository $users)
    {
        $this->db = $db;
        $this->repository = $repository;
        $this->users = $users;
        $this->notifications = new Notification($db);
        $this->auditLogs = new AuditLog($db);
    }

    public function deduct(array $payload, array $actor, string $ipAddress): array
    {
        return $this->repository->inTransaction(function () use ($payload, $actor, $ipAddress): array {
            $term = (int) ($payload['term'] ?? 0);
            $marksToDeduct = (int) ($payload['marks_to_deduct'] ?? 0);
            $actionType = in_array((string) ($payload['action_type'] ?? 'deduct'), ['add', 'deduct', 'set'], true)
                ? (string) $payload['action_type']
                : 'deduct';

            $settings = new \Settings($this->db);
            $maxMarksPerTerm = (int) $settings->get('max_marks_per_term', '40');
            $maxMarksPerTerm = max(1, min(40, $maxMarksPerTerm));

            $studentIds = [];
            $classId = (int) ($payload['class_id'] ?? 0);
            if (!empty($payload['student_id'])) {
                $studentIds = [(int) $payload['student_id']];
            } elseif ($classId > 0) {
                $studentIds = $this->repository->getStudentIdsByClass($classId);
            }

            if ($studentIds === []) {
                throw new RuntimeException('Student or class selection is required.');
            }

            $processedStudents = [];
            $actorId = (int) $actor['id'];
            $reason = (string) ($payload['reason'] ?? '');

            foreach ($studentIds as $studentId) {
                $snapshot = $this->repository->getStudentTermForUpdate($studentId, $term);
                if (!$snapshot) {
                    throw new RuntimeException('Student or marks record not found.');
                }

                $currentMarks = (int) $snapshot['current_marks'];
                $currentMarks = max(0, min($maxMarksPerTerm, $currentMarks));

                if ($actionType === 'set') {
                    $this->repository->setMarks($studentId, $term, $marksToDeduct);

                    try {
                        $riskService = new RiskEngineService($this->db);
                        $riskService->assessStudent($studentId, $term);
                    } catch (\Throwable $riskException) {
                        Database::logError('Risk reassessment failed after manual marks update', [
                            'student_id' => $studentId,
                            'exception' => $riskException->getMessage(),
                        ]);
                    }

                    $this->auditLogs->create(
                        $actorId,
                        'set_marks',
                        sprintf(
                            'Set marks to %d for %s (%s) for term %d.',
                            $marksToDeduct,
                            $snapshot['full_name'],
                            $snapshot['student_code'],
                            $term
                        ),
                        $ipAddress
                    );

                    $processedStudents[] = [
                        'student_id' => $studentId,
                        'term' => $term,
                        'updated_marks' => $marksToDeduct,
                        'student_name' => (string) $snapshot['full_name'],
                        'student_code' => (string) $snapshot['student_code'],
                    ];
                    $this->syncLowMarksLetter($studentId, $term, $marksToDeduct, '');
                    continue;
                }

                if ($actionType === 'add' && ($currentMarks + $marksToDeduct) > $maxMarksPerTerm) {
                    throw new RuntimeException(sprintf('Marks cannot exceed %d per term.', $maxMarksPerTerm));
                }

                $effectiveMarksToApply = $actionType === 'deduct'
                    ? min($marksToDeduct, $currentMarks)
                    : min($marksToDeduct, $maxMarksPerTerm - $currentMarks);

                if ($actionType === 'deduct' && $effectiveMarksToApply <= 0) {
                    continue;
                }

                $actualMarksDelta = $actionType === 'add'
                    ? -abs($effectiveMarksToApply)
                    : abs($effectiveMarksToApply);

                $this->repository->createHistory(
                    $studentId,
                    $term,
                    $actualMarksDelta,
                    $reason,
                    $actorId
                );
                $this->repository->deductMarks($studentId, $term, $actualMarksDelta);

                try {
                    $riskService = new RiskEngineService($this->db);
                    $riskService->assessStudent($studentId, $term);
                } catch (\Throwable $riskException) {
                    Database::logError('Risk reassessment failed after discipline action', [
                        'student_id' => $studentId,
                        'exception' => $riskException->getMessage(),
                    ]);
                }

                $updatedMarks = max(0, min($maxMarksPerTerm, $currentMarks - $actualMarksDelta));
                $actionWord = $actionType === 'add'
                    ? 'added/rewarded'
                    : 'lost';
                $message = sprintf(
                    '%s %s %d marks in term %d. Reason: %s',
                    $snapshot['full_name'],
                    $actionWord,
                    $effectiveMarksToApply,
                    $term,
                    $reason
                );

                $recipients = $this->notifications->resolveDeductionRecipients($snapshot);
                $this->notifications->createBulk($recipients, $message, Notification::PRIORITY_LOW, (int) ($snapshot['class_id'] ?? 0) ?: null);
                $auditAction = $actionType === 'add'
                    ? 'add_marks'
                    : 'deduct_marks';
                $auditLabel = $actionType === 'add'
                    ? 'Added'
                    : 'Deducted';
                $this->auditLogs->create(
                    $actorId,
                    $auditAction,
                    sprintf(
                        '%s %d marks to/from %s (%s) for term %d. Reason: %s',
                        $auditLabel,
                        $effectiveMarksToApply,
                        $snapshot['full_name'],
                        $snapshot['student_code'],
                        $term,
                        $reason
                    ),
                    $ipAddress
                );

                $processedStudents[] = [
                    'student_id' => $studentId,
                    'term' => $term,
                    'updated_marks' => $updatedMarks,
                    'student_name' => (string) $snapshot['full_name'],
                    'student_code' => (string) $snapshot['student_code'],
                ];
                $this->syncLowMarksLetter($studentId, $term, $updatedMarks, $reason);
            }

            if (count($processedStudents) === 1) {
                return $processedStudents[0];
            }

            return [
                'class_id' => $classId,
                'term' => $term,
                'updated_count' => count($processedStudents),
                'students' => $processedStudents,
            ];
        });
    }

    public function canDeduct(array $actor): bool
    {
        try {
            return $this->users->hasPermission((int) $actor['id'], 'deduct_marks');
        } catch (\Throwable $exception) {
            Database::logError('API permission lookup failed', ['exception' => $exception->getMessage()]);
            $fallback = array_filter(array_map('trim', explode(',', strtolower(getenv('SBMS_DEDUCT_MARKS_ROLES') ?: 'teacher,admin,headteacher,deputy headteacher discipline'))));
            return in_array(strtolower((string) ($actor['role_name'] ?? '')), $fallback, true);
        }
    }

    private function syncLowMarksLetter(int $studentId, int $term, int $updatedMarks, string $reason = ''): void
    {
        try {
            $settings = new \Settings($this->db);
            if ((string) $settings->get('low_marks_letter_enabled', '1') !== '1') {
                return;
            }

            $threshold = max(0, min(40, (int) $settings->get('low_marks_threshold', '20')));
            if ($updatedMarks >= $threshold) {
                return;
            }

            $letterService = new LetterService($this->db);
            $letter = $letterService->generateParentInvitationLetter($studentId, $term, $updatedMarks, $threshold, $reason);
            if ($letter === null) {
                return;
            }

            $notificationService = new ParentNotificationService($this->db);
            $notificationService->notifyParentsOfLetter(
                $studentId,
                (string) ($letter['subject'] ?? 'Parent Invitation Letter'),
                (string) ($letter['body'] ?? '')
            );
        } catch (\Throwable $exception) {
            Database::logError('Auto letter sync failed', [
                'student_id' => $studentId,
                'term' => $term,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
