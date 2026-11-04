<?php

namespace App\Services;

use PDO;
use DateTime;

/**
 * CollaborativeService - Manages incident comments, follow-up actions, and team collaboration
 */
class CollaborativeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Add comment to incident
     */
    public function addComment(
        int $deductionId,
        int $userId,
        string $comment,
        bool $isInternal = false
    ): ?int {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO incident_comments (deduction_id, user_id, comment_text, is_internal)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([$deductionId, $userId, $comment, $isInternal ? 1 : 0]);
            return (int)$this->pdo->lastInsertId();
        } catch (\Exception $e) {
            error_log("Error adding comment: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all comments for an incident
     */
    public function getIncidentComments(int $deductionId, bool $includeInternal = true): array
    {
        try {
            $sql = "
                SELECT 
                    ic.id,
                    ic.comment_text,
                    ic.is_internal,
                    ic.created_at,
                    ic.updated_at,
                    u.full_name as commented_by,
                    u.id as user_id
                FROM incident_comments ic
                JOIN users u ON ic.user_id = u.id
                WHERE ic.deduction_id = ?
            ";

            if (!$includeInternal) {
                $sql .= " AND ic.is_internal = FALSE";
            }

            $sql .= " ORDER BY ic.created_at ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$deductionId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching comments: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create follow-up action
     */
    public function createAction(
        int $deductionId,
        int $assignedToId,
        string $title,
        string $type,
        ?string $description = null,
        ?string $dueDate = null
    ): ?int {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO incident_actions 
                (deduction_id, assigned_to_id, action_title, action_type, description, due_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$deductionId, $assignedToId, $title, $type, $description, $dueDate]);
            
            $actionId = (int)$this->pdo->lastInsertId();

            // Create calendar event if due date provided
            if ($dueDate) {
                $this->createActionCalendarEvent($actionId, $deductionId, $assignedToId, $title, $dueDate);
            }

            return $actionId;
        } catch (\Exception $e) {
            error_log("Error creating action: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get pending actions for user
     */
    public function getPendingActions(int $userId, string $filterStatus = 'pending'): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ia.id,
                    ia.action_title,
                    ia.action_type,
                    ia.description,
                    ia.due_date,
                    ia.status,
                    ia.created_at,
                    s.full_name as student_name,
                    dh.reason as incident_reason
                FROM incident_actions ia
                JOIN deductions_history dh ON ia.deduction_id = dh.id
                JOIN students s ON dh.student_id = s.id
                WHERE ia.assigned_to_id = ? AND ia.status = ?
                ORDER BY ia.due_date ASC, ia.created_at DESC
            ");
            
            $stmt->execute([$userId, $filterStatus]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching actions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark action as completed
     */
    public function completeAction(int $actionId, int $userId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE incident_actions
                SET status = 'completed', completed_at = NOW()
                WHERE id = ? AND assigned_to_id = ?
            ");
            
            return $stmt->execute([$actionId, $userId]);
        } catch (\Exception $e) {
            error_log("Error completing action: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update action status
     */
    public function updateActionStatus(int $actionId, string $status): bool
    {
        try {
            $validStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($status, $validStatuses)) {
                return false;
            }

            $stmt = $this->pdo->prepare("
                UPDATE incident_actions
                SET status = ?, completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
                WHERE id = ?
            ");
            
            return $stmt->execute([$status, $status, $actionId]);
        } catch (\Exception $e) {
            error_log("Error updating action status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get actions for an incident
     */
    public function getIncidentActions(int $deductionId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ia.id,
                    ia.action_title,
                    ia.action_type,
                    ia.description,
                    ia.due_date,
                    ia.status,
                    ia.created_at,
                    u.full_name as assigned_to
                FROM incident_actions ia
                JOIN users u ON ia.assigned_to_id = u.id
                WHERE ia.deduction_id = ?
                ORDER BY ia.due_date ASC
            ");
            
            $stmt->execute([$deductionId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching incident actions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create calendar event for follow-up action
     */
    private function createActionCalendarEvent(
        int $actionId,
        int $deductionId,
        int $createdById,
        string $title,
        string $dueDate
    ): void {
        try {
            // Get deduction student info
            $stmt = $this->pdo->prepare("
                SELECT student_id, reason FROM deductions_history WHERE id = ?
            ");
            $stmt->execute([$deductionId]);
            $deduction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$deduction) return;

            $stmt = $this->pdo->prepare("
                INSERT INTO calendar_events 
                (event_type, event_title, description, student_id, related_deduction_id, event_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                'follow_up',
                $title,
                "Follow-up for: {$deduction['reason']}",
                $deduction['student_id'],
                $deductionId,
                $dueDate,
                $createdById
            ]);
        } catch (\Exception $e) {
            error_log("Error creating action calendar event: " . $e->getMessage());
        }
    }

    /**
     * Get incident summary with comments and actions
     */
    public function getIncidentDetails(int $deductionId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    dh.id,
                    dh.student_id,
                    dh.term,
                    dh.marks_deducted,
                    dh.reason,
                    dh.timestamp,
                    s.full_name as student_name,
                    u.full_name as performed_by
                FROM deductions_history dh
                JOIN students s ON dh.student_id = s.id
                LEFT JOIN users u ON dh.performed_by = u.id
                WHERE dh.id = ?
            ");

            $stmt->execute([$deductionId]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$incident) return [];

            $incident['comments'] = $this->getIncidentComments($deductionId);
            $incident['actions'] = $this->getIncidentActions($deductionId);

            return $incident;
        } catch (\Exception $e) {
            error_log("Error fetching incident details: " . $e->getMessage());
            return [];
        }
    }
}
