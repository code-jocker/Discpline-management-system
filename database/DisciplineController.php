<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/models/Deduction.php';
require_once dirname(__DIR__) . '/models/Student.php';
require_once dirname(__DIR__) . '/models/ClassModel.php';
require_once dirname(__DIR__) . '/models/Notification.php';

final class DisciplineController
{
    private Deduction $deductions;
    private Student $students;
    private ClassModel $classes;

    public function __construct()
    {
        $db = Database::connection();
        $this->deductions = new Deduction($db);
        $this->students = new Student($db);
        $this->classes = new ClassModel($db);
    }

    public function pageData(?array $authUser): array
    {
        return [
            'students' => $this->students->listForSelection(),
            'classes' => $this->classes->all(),
            'can_deduct' => current_user_has_permission('deduct_marks')
        ];
    }

    public function fetchHistory(array $query): array
    {
        $filters = [
            'student_id' => !empty($query['student_id']) ? (int)$query['student_id'] : null,
            'class_id' => !empty($query['class_id']) ? (int)$query['class_id'] : null,
            'term' => !empty($query['term']) ? (int)$query['term'] : null,
        ];

        $page = max(1, (int)($query['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $sort = $query['sort'] ?? 'latest';

        $history = $this->deductions->fetchHistory($filters, $limit, $offset, $sort);
        $total = $this->deductions->countHistory($filters);

        return [
            'status' => 'success',
            'data' => $history,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    public function deduct(array $input, int $performedBy): array
    {
        $studentId = (int)($input['student_id'] ?? 0);
        $term = (int)($input['term'] ?? 0);
        $amount = (int)($input['marks_to_deduct'] ?? 0);
        $reason = trim($input['reason'] ?? '');

        if (!$studentId || !$term || $amount <= 0 || empty($reason)) {
            return ['status' => 'error', 'message' => 'Please fill in all deduction details.'];
        }

        try {
            $db = Database::connection();
            $db->beginTransaction();

            $this->deductions->insertHistory($studentId, $term, $amount, $reason, $performedBy);
            $this->deductions->applyMarksDeduction($studentId, $term, $amount);

            // Send Notifications
            $notif = new Notification($db);
            $snapshot = $this->deductions->getStudentTermSnapshot($studentId, $term);
            $recipients = $notif->resolveDeductionRecipients($snapshot);
            $notif->createBulk($recipients, "Discipline action: {$snapshot['full_name']} lost {$amount} marks.");

            $db->commit();
            return ['status' => 'success', 'message' => 'Marks deducted and notifications sent.'];
        } catch (Throwable $e) {
            if (Database::connection()->inTransaction()) {
                Database::connection()->rollBack();
            }
            Database::logError("Deduction failed", ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Internal error during deduction.'];
        }
    }
}