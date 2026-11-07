<?php

namespace App\Services;

use PDO;
use DateTime;

/**
 * CalendarService - Manages calendar events and scheduling
 */
class CalendarService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create calendar event
     */
    public function createEvent(
        string $eventType,
        string $title,
        string $description,
        string $eventDate,
        int $createdBy,
        ?int $studentId = null,
        ?int $classId = null,
        ?int $deductionId = null,
        ?string $eventTime = null,
        ?string $location = null
    ): ?int {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO calendar_events 
                (event_type, event_title, description, student_id, class_id, related_deduction_id, event_date, event_time, location, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $eventType,
                $title,
                $description,
                $studentId,
                $classId,
                $deductionId,
                $eventDate,
                $eventTime,
                $location,
                $createdBy
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (\Exception $e) {
            error_log("Error creating calendar event: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get events for date range
     */
    public function getEventsForDateRange(string $startDate, string $endDate, ?int $classId = null): array
    {
        try {
            $sql = "
                SELECT 
                    ce.id,
                    ce.event_type,
                    ce.event_title,
                    ce.description,
                    ce.event_date,
                    ce.event_time,
                    ce.location,
                    s.full_name as student_name,
                    c.name as class_name,
                    u.full_name as created_by
                FROM calendar_events ce
                LEFT JOIN students s ON ce.student_id = s.id
                LEFT JOIN classes c ON ce.class_id = c.id
                JOIN users u ON ce.created_by = u.id
                WHERE ce.event_date BETWEEN ? AND ?
            ";

            $params = [$startDate, $endDate];

            if ($classId) {
                $sql .= " AND ce.class_id = ?";
                $params[] = $classId;
            }

            $sql .= " ORDER BY ce.event_date ASC, ce.event_time ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching calendar events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get events for specific student
     */
    public function getStudentEvents(int $studentId, string $monthYear = null): array
    {
        try {
            $monthYear = $monthYear ?? date('Y-m');

            $stmt = $this->pdo->prepare("
                SELECT 
                    ce.id,
                    ce.event_type,
                    ce.event_title,
                    ce.description,
                    ce.event_date,
                    ce.event_time,
                    ce.location,
                    u.full_name as created_by
                FROM calendar_events ce
                JOIN users u ON ce.created_by = u.id
                WHERE ce.student_id = ? AND DATE_FORMAT(ce.event_date, '%Y-%m') = ?
                ORDER BY ce.event_date ASC
            ");

            $stmt->execute([$studentId, $monthYear]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching student events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get calendar month view
     */
    public function getMonthView(int $year, int $month, ?int $classId = null): array
    {
        try {
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = date('Y-m-t', strtotime($startDate));

            $events = $this->getEventsForDateRange($startDate, $endDate, $classId);

            // Group by date
            $calendar = [];
            foreach ($events as $event) {
                $date = $event['event_date'];
                if (!isset($calendar[$date])) {
                    $calendar[$date] = [];
                }
                $calendar[$date][] = $event;
            }

            return $calendar;
        } catch (\Exception $e) {
            error_log("Error fetching month view: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get upcoming events (next 30 days)
     */
    public function getUpcomingEvents(int $limit = 10, ?int $classId = null): array
    {
        try {
            $today = date('Y-m-d');
            $future = date('Y-m-d', strtotime('+30 days'));
            $limit = max(1, min(100, $limit));

            $sql = "
                SELECT
                    ce.id,
                    ce.event_type,
                    ce.event_title,
                    ce.description,
                    ce.event_date,
                    ce.event_time,
                    s.full_name as student_name,
                    c.name as class_name
                FROM calendar_events ce
                LEFT JOIN students s ON ce.student_id = s.id
                LEFT JOIN classes c ON ce.class_id = c.id
                WHERE ce.event_date BETWEEN ? AND ?
            ";

            $params = [$today, $future];
            if ($classId) {
                $sql .= " AND ce.class_id = ?";
                $params[] = $classId;
            }

            $sql .= " ORDER BY ce.event_date ASC, ce.event_time ASC LIMIT " . (int) $limit;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching upcoming events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete calendar event
     */
    public function deleteEvent(int $eventId): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM calendar_events WHERE id = ?");
            return $stmt->execute([$eventId]);
        } catch (\Exception $e) {
            error_log("Error deleting event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update event
     */
    public function updateEvent(int $eventId, array $updates): bool
    {
        try {
            $allowedFields = ['event_title', 'description', 'event_date', 'event_time', 'location'];
            $setClause = [];
            $params = [];

            foreach ($updates as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $setClause[] = "{$field} = ?";
                    $params[] = $value;
                }
            }

            if (empty($setClause)) return false;

            $params[] = $eventId;
            $sql = "UPDATE calendar_events SET " . implode(', ', $setClause) . " WHERE id = ?";

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            error_log("Error updating event: " . $e->getMessage());
            return false;
        }
    }
}
