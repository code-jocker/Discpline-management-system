<?php

namespace App\Controllers;

use App\Services\RewardsService;
use App\Services\ParentNotificationService;

class RewardsController
{
    private RewardsService $rewardsService;

    public function __construct(RewardsService $rewardsService)
    {
        $this->rewardsService = $rewardsService;
    }

    /**
     * Get student achievements
     * GET /api/v1/students/{id}/achievements
     */
    public function getAchievements(): void
    {
        $studentId = (int)($_GET['student_id'] ?? ($_GET['id'] ?? 0));
        if (!$studentId) {
            http_response_code(400);
            echo json_encode(['error' => 'student_id required']);
            return;
        }

        $achievements = $this->rewardsService->getStudentAchievements($studentId);
        $rewards = $this->rewardsService->getStudentRewards($studentId);

        echo json_encode([
            'rewards' => $rewards,
            'achievements' => $achievements,
            'total_count' => count($achievements)
        ]);
    }

    /**
     * Get class leaderboard
     * GET /api/v1/classes/{id}/leaderboard
     */
    public function getLeaderboard(): void
    {
        $classId = (int)($_GET['class_id'] ?? ($_GET['id'] ?? 0));
        $monthYear = $_GET['month_year'] ?? null;

        if (!$classId) {
            http_response_code(400);
            echo json_encode(['error' => 'class_id required']);
            return;
        }

        $leaderboard = $this->rewardsService->getClassLeaderboard($classId, $monthYear);

        echo json_encode([
            'leaderboard' => $leaderboard,
            'count' => count($leaderboard),
            'month_year' => $monthYear ?? date('Y-m')
        ]);
    }

    /**
     * Build monthly leaderboard (admin function)
     * POST /api/v1/leaderboards/build
     */
    public function buildLeaderboard(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $classId = (int)($data['class_id'] ?? 0);
        $monthYear = $data['month_year'] ?? null;

        if (!$classId) {
            http_response_code(400);
            echo json_encode(['error' => 'class_id required']);
            return;
        }

        $this->rewardsService->buildMonthlyLeaderboard($classId, $monthYear);

        echo json_encode([
            'success' => true,
            'message' => 'Leaderboard built successfully'
        ]);
    }
}
