<?php

namespace App\Controllers;

use App\Services\CalendarService;

class CalendarController
{
    private CalendarService $calendarService;

    public function __construct(CalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    /**
     * Get calendar events for date range
     * GET /api/v1/calendar/events?start_date=...&end_date=...&class_id=...
     */
    public function getEvents(): void
    {
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        $classId = (int)($_GET['class_id'] ?? 0);

        $events = $this->calendarService->getEventsForDateRange(
            $startDate,
            $endDate,
            $classId ?: null
        );

        echo json_encode([
            'events' => $events,
            'count' => count($events),
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
    }

    /**
     * Get month view
     * GET /api/v1/calendar/month?year=...&month=...&class_id=...
     */
    public function getMonthView(): void
    {
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('m'));
        $classId = (int)($_GET['class_id'] ?? 0);

        $calendar = $this->calendarService->getMonthView($year, $month, $classId ?: null);

        echo json_encode([
            'calendar' => $calendar,
            'month_year' => sprintf('%04d-%02d', $year, $month)
        ]);
    }

    /**
     * Get student events
     * GET /api/v1/students/{id}/calendar-events
     */
    public function getStudentEvents(): void
    {
        $studentId = (int)($_GET['student_id'] ?? 0);
        $monthYear = $_GET['month_year'] ?? null;

        if (!$studentId) {
            http_response_code(400);
            echo json_encode(['error' => 'student_id required']);
            return;
        }

        $events = $this->calendarService->getStudentEvents($studentId, $monthYear);

        echo json_encode([
            'events' => $events,
            'count' => count($events)
        ]);
    }

    /**
     * Get upcoming events
     * GET /api/v1/calendar/upcoming?limit=...
     */
    public function getUpcomingEvents(): void
    {
        $limit = (int)($_GET['limit'] ?? 10);
        $classId = (int)($_GET['class_id'] ?? 0);
        $events = $this->calendarService->getUpcomingEvents($limit, $classId ?: null);

        echo json_encode([
            'events' => $events,
            'count' => count($events)
        ]);
    }

    /**
     * Create calendar event
     * POST /api/v1/calendar/events
     */
    public function createEvent(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['event_type', 'event_title', 'event_date', 'created_by'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: {$field}"]);
                return;
            }
        }

        $eventId = $this->calendarService->createEvent(
            $data['event_type'],
            $data['event_title'],
            $data['description'] ?? '',
            $data['event_date'],
            (int)$data['created_by'],
            (int)($data['student_id'] ?? 0) ?: null,
            (int)($data['class_id'] ?? 0) ?: null,
            (int)($data['deduction_id'] ?? 0) ?: null,
            $data['event_time'] ?? null,
            $data['location'] ?? null
        );

        if (!$eventId) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create event']);
            return;
        }

        echo json_encode([
            'success' => true,
            'event_id' => $eventId,
            'message' => 'Calendar event created successfully'
        ]);
    }

    /**
     * Delete calendar event
     * DELETE /api/v1/calendar/events/{id}
     */
    public function deleteEvent(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $eventId = (int)($data['event_id'] ?? 0);

        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['error' => 'event_id required']);
            return;
        }

        $success = $this->calendarService->deleteEvent($eventId);

        if (!$success) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete event']);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Event deleted successfully'
        ]);
    }
}
