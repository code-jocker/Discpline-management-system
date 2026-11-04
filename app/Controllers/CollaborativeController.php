<?php

namespace App\Controllers;

use App\Services\CollaborativeService;
use App\Services\ParentNotificationService;

class CollaborativeController
{
    private CollaborativeService $collaborativeService;
    private ParentNotificationService $notificationService;

    public function __construct(
        CollaborativeService $collaborativeService,
        ParentNotificationService $notificationService
    ) {
        $this->collaborativeService = $collaborativeService;
        $this->notificationService = $notificationService;
    }

    /**
     * Get incident with all comments and actions
     * GET /api/v1/incidents/{id}/details
     */
    public function getIncidentDetails(): void
    {
        $deductionId = (int)($_GET['deduction_id'] ?? 0);
        if (!$deductionId) {
            http_response_code(400);
            echo json_encode(['error' => 'deduction_id required']);
            return;
        }

        $incident = $this->collaborativeService->getIncidentDetails($deductionId);
        if (empty($incident)) {
            http_response_code(404);
            echo json_encode(['error' => 'Incident not found']);
            return;
        }

        echo json_encode($incident);
    }

    /**
     * Add comment to incident
     * POST /api/v1/incidents/{id}/comments
     */
    public function addComment(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $deductionId = (int)($data['deduction_id'] ?? 0);
        $userId = (int)($data['user_id'] ?? 0);
        $comment = trim($data['comment'] ?? '');
        $isInternal = (bool)($data['is_internal'] ?? false);

        if (!$deductionId || !$userId || !$comment) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        $commentId = $this->collaborativeService->addComment($deductionId, $userId, $comment, $isInternal);

        if (!$commentId) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add comment']);
            return;
        }

        echo json_encode([
            'success' => true,
            'comment_id' => $commentId,
            'message' => 'Comment added successfully'
        ]);
    }

    /**
     * Create follow-up action
     * POST /api/v1/incidents/{id}/actions
     */
    public function createAction(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $deductionId = (int)($data['deduction_id'] ?? 0);
        $assignedToId = (int)($data['assigned_to_id'] ?? 0);
        $title = trim($data['title'] ?? '');
        $type = $data['action_type'] ?? 'follow_up';
        $description = $data['description'] ?? null;
        $dueDate = $data['due_date'] ?? null;

        if (!$deductionId || !$assignedToId || !$title) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        $actionId = $this->collaborativeService->createAction(
            $deductionId,
            $assignedToId,
            $title,
            $type,
            $description,
            $dueDate
        );

        if (!$actionId) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create action']);
            return;
        }

        echo json_encode([
            'success' => true,
            'action_id' => $actionId,
            'message' => 'Action created successfully'
        ]);
    }

    /**
     * Get pending actions for user
     * GET /api/v1/users/{id}/pending-actions
     */
    public function getPendingActions(): void
    {
        $userId = (int)($_GET['user_id'] ?? 0);
        $status = $_GET['status'] ?? 'pending';

        if (!$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            return;
        }

        $actions = $this->collaborativeService->getPendingActions($userId, $status);

        echo json_encode([
            'actions' => $actions,
            'count' => count($actions)
        ]);
    }

    /**
     * Mark action as completed
     * PATCH /api/v1/actions/{id}/complete
     */
    public function completeAction(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $actionId = (int)($data['action_id'] ?? 0);
        $userId = (int)($data['user_id'] ?? 0);

        if (!$actionId || !$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        $success = $this->collaborativeService->completeAction($actionId, $userId);

        if (!$success) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to complete action']);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Action marked as completed'
        ]);
    }

    /**
     * Update action status
     * PATCH /api/v1/actions/{id}/status
     */
    public function updateActionStatus(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $actionId = (int)($data['action_id'] ?? 0);
        $status = $data['status'] ?? '';

        if (!$actionId || !$status) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        $success = $this->collaborativeService->updateActionStatus($actionId, $status);

        if (!$success) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update action status']);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Action status updated'
        ]);
    }
}
