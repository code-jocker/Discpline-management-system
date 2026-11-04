<?php

namespace App\Controllers;

use App\Services\ReportService;

class AnalyticsController
{
    private ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get dashboard analytics for class
     * GET /api/v1/analytics/dashboard?class_id=...&term=...
     */
    public function getDashboardAnalytics(): void
    {
        $classId = (int)($_GET['class_id'] ?? 0);
        $term = (int)($_GET['term'] ?? 1);

        if (!$classId) {
            http_response_code(400);
            echo json_encode(['error' => 'class_id required']);
            return;
        }

        $analytics = $this->reportService->getDashboardAnalytics($classId, $term);

        echo json_encode([
            'analytics' => $analytics,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get incident analytics by category
     * GET /api/v1/analytics/incidents?class_id=...&term=...
     */
    public function getIncidentAnalytics(): void
    {
        $classId = (int)($_GET['class_id'] ?? 0);
        $term = (int)($_GET['term'] ?? 1);

        if (!$classId) {
            http_response_code(400);
            echo json_encode(['error' => 'class_id required']);
            return;
        }

        $analytics = $this->reportService->getIncidentAnalytics($classId, $term);

        echo json_encode([
            'incident_categories' => $analytics,
            'count' => count($analytics),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Generate term report
     * POST /api/v1/reports/term
     */
    public function generateTermReport(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $studentId = (int)($data['student_id'] ?? 0);
        $term = (int)($data['term'] ?? 1);
        $generatedBy = (int)($data['generated_by'] ?? 0);
        $format = $data['format'] ?? 'pdf';

        if (!$studentId || !$generatedBy) {
            http_response_code(400);
            echo json_encode(['error' => 'student_id and generated_by required']);
            return;
        }

        $result = $this->reportService->generateTermReport($studentId, $term, $generatedBy, $format);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            return;
        }

        echo json_encode($result);
    }

    /**
     * Generate class progress report
     * POST /api/v1/reports/class-progress
     */
    public function generateClassProgressReport(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $classId = (int)($data['class_id'] ?? 0);
        $term = (int)($data['term'] ?? 1);
        $generatedBy = (int)($data['generated_by'] ?? 0);
        $format = $data['format'] ?? 'pdf';

        if (!$classId || !$generatedBy) {
            http_response_code(400);
            echo json_encode(['error' => 'class_id and generated_by required']);
            return;
        }

        $result = $this->reportService->generateClassProgressReport($classId, $term, $generatedBy, $format);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            return;
        }

        echo json_encode($result);
    }
}
