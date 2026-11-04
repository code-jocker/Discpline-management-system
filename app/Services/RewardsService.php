<?php

namespace App\Services;

use PDO;
use DateTime;

/**
 * RewardsService - Manages student achievements, points, and gamification
 * Provides behavioral rewards system with milestones, badges, and leaderboards
 */
class RewardsService
{
    private PDO $pdo;
    private ParentNotificationService $notificationService;

    public function __construct(PDO $pdo, ParentNotificationService $notificationService)
    {
        $this->pdo = $pdo;
        $this->notificationService = $notificationService;
    }

    /**
     * Award achievement to student
     */
    public function awardAchievement(
        int $studentId,
        string $type,
        string $title,
        string $description,
        int $points = 10,
        ?string $badgeIcon = null
    ): ?int {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO achievements (student_id, achievement_type, title, description, points_awarded, badge_icon)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$studentId, $type, $title, $description, $points, $badgeIcon]);
            $achievementId = (int)$this->pdo->lastInsertId();

            // Update student rewards points
            $this->addRewardPoints($studentId, $points);

            // Notify parents of achievement
            $this->notificationService->notifyParentsOfAchievement($studentId, $title);

            return $achievementId;
        } catch (\Exception $e) {
            error_log("RewardsService error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Award achievement based on marks improvement
     */
    public function checkAndAwardMilestones(int $studentId, int $currentMarks): void
    {
        try {
            // Get previous marks
            $stmt = $this->pdo->prepare("
                SELECT marks FROM student_marks
                WHERE student_id = ? AND term = ?
                ORDER BY term DESC LIMIT 1 OFFSET 1
            ");
            
            $currentTerm = $this->getCurrentTerm();
            $stmt->execute([$studentId, $currentTerm - 1]);
            $previous = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$previous) return;

            $previousMarks = (int)$previous['marks'];
            $improvement = $currentMarks - $previousMarks;

            // Award based on milestones
            if ($currentMarks >= 35) {
                $this->awardAchievement(
                    $studentId,
                    'marks_milestone',
                    '🏆 Excellent Marks',
                    'Achieved 35+ marks (excellent behavior)',
                    25
                );
            } elseif ($improvement >= 5) {
                $this->awardAchievement(
                    $studentId,
                    'improvement',
                    '📈 Great Improvement',
                    "Improved by {$improvement} marks",
                    15
                );
            } elseif ($currentMarks >= 30) {
                $this->awardAchievement(
                    $studentId,
                    'marks_milestone',
                    '⭐ Good Marks',
                    'Achieved 30+ marks (good behavior)',
                    10
                );
            }
        } catch (\Exception $e) {
            error_log("Milestone check error: " . $e->getMessage());
        }
    }

    /**
     * Add reward points to student
     */
    public function addRewardPoints(int $studentId, int $points): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO student_rewards (student_id, total_points)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE total_points = total_points + ?
            ");
            $stmt->execute([$studentId, $points, $points]);
        } catch (\Exception $e) {
            error_log("Error adding reward points: " . $e->getMessage());
        }
    }

    /**
     * Get student's current points and streak
     */
    public function getStudentRewards(int $studentId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT total_points, current_streak, last_update
                FROM student_rewards
                WHERE student_id = ?
            ");
            $stmt->execute([$studentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ?: ['total_points' => 0, 'current_streak' => 0];
        } catch (\Exception $e) {
            error_log("Error fetching rewards: " . $e->getMessage());
            return ['total_points' => 0, 'current_streak' => 0];
        }
    }

    /**
     * Get student achievements
     */
    public function getStudentAchievements(int $studentId, int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, achievement_type, title, description, points_awarded, badge_icon, created_at
                FROM achievements
                WHERE student_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$studentId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            error_log("Error fetching achievements: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Build monthly leaderboard for a class
     */
    public function buildMonthlyLeaderboard(int $classId, string $monthYear = null): void
    {
        try {
            $monthYear = $this->normalizeMonthYear($monthYear);
            $rankings = $this->calculateLeaderboardRows($classId, $monthYear);

            if (!\Database::tableExists('monthly_leaderboards')) {
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM monthly_leaderboards
                 WHERE month_year = ?
                   AND student_id IN (SELECT id FROM students WHERE class_id = ?)'
            );
            $stmt->execute([$monthYear, $classId]);

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO monthly_leaderboards (student_id, month_year, rank, points, marks_avg, incidents_count)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($rankings as $index => $ranking) {
                $insertStmt->execute([
                    $ranking['id'],
                    $monthYear,
                    $index + 1,
                    $ranking['points'],
                    $ranking['marks_avg'],
                    $ranking['incidents_count'],
                ]);
            }
        } catch (\Exception $e) {
            error_log("Error building leaderboard: " . $e->getMessage());
        }
    }

    /**
     * Get class leaderboard
     */
    public function getClassLeaderboard(int $classId, string $monthYear = null): array
    {
        try {
            $monthYear = $this->normalizeMonthYear($monthYear);

            if (\Database::tableExists('monthly_leaderboards')) {
                $stmt = $this->pdo->prepare(
                    'SELECT
                        ml.rank,
                        s.id,
                        s.full_name,
                        s.student_code,
                        ml.points,
                        ml.marks_avg,
                        ml.incidents_count
                     FROM monthly_leaderboards ml
                     JOIN students s ON ml.student_id = s.id
                     WHERE s.class_id = ? AND ml.month_year = ?
                     ORDER BY ml.rank ASC'
                );
                $stmt->execute([$classId, $monthYear]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if ($rows !== []) {
                    return $rows;
                }
            }

            return $this->calculateLeaderboardRows($classId, $monthYear);
        } catch (\Exception $e) {
            error_log("Error fetching leaderboard: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get current academic term
     */
    private function getCurrentTerm(): int
    {
        $month = (int)date('m');
        // Assuming: Term 1 = 1-4 (Jan-Apr), Term 2 = 5-8 (May-Aug), Term 3 = 9-12 (Sep-Dec)
        if ($month <= 4) return 1;
        if ($month <= 8) return 2;
        return 3;
    }

    private function normalizeMonthYear(?string $monthYear): string
    {
        if (is_string($monthYear) && preg_match('/^\d{4}-\d{2}$/', $monthYear) === 1) {
            return $monthYear;
        }

        return date('Y-m');
    }

    /**
     * Calculate leaderboard rows without relying on the cached table.
     *
     * @return array<int, array{id:int, full_name:string, student_code:string, points:int, marks_avg:int, incidents_count:int, rank:int}>
     */
    private function calculateLeaderboardRows(int $classId, string $monthYear): array
    {
        $studentMarksSubquery = '
            SELECT sm1.student_id, sm1.marks
            FROM student_marks sm1
            INNER JOIN (
                SELECT student_id, MAX(term) AS latest_term
                FROM student_marks
                GROUP BY student_id
            ) latest ON latest.student_id = sm1.student_id AND latest.latest_term = sm1.term
        ';

        $incidentCountSubquery = '
            SELECT dh.student_id, COUNT(*) AS cnt
            FROM deductions_history dh
            WHERE dh.marks_deducted > 0
              AND DATE_FORMAT(dh.`timestamp`, "%Y-%m") = :month_year
            GROUP BY dh.student_id
        ';

        $joinRewards = \Database::tableExists('student_rewards')
            ? 'LEFT JOIN student_rewards sr ON sr.student_id = s.id'
            : '';
        $pointsSelect = \Database::tableExists('student_rewards')
            ? 'COALESCE(sr.total_points, 0) AS points,'
            : '0 AS points,';

        $sql = '
            SELECT
                s.id,
                s.full_name,
                s.student_code,
                ' . $pointsSelect . '
                COALESCE(sm_latest.marks, 40) AS marks_avg,
                COALESCE(dh_count.cnt, 0) AS incidents_count
            FROM students s
            ' . $joinRewards . '
            LEFT JOIN (' . $studentMarksSubquery . ') sm_latest ON sm_latest.student_id = s.id
            LEFT JOIN (' . $incidentCountSubquery . ') dh_count ON dh_count.student_id = s.id
            WHERE s.class_id = :class_id
            ORDER BY points DESC, marks_avg DESC, incidents_count ASC, s.full_name ASC
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'class_id' => $classId,
            'month_year' => $monthYear,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
            $rows[$index]['points'] = (int) ($row['points'] ?? 0);
            $rows[$index]['marks_avg'] = (int) round((float) ($row['marks_avg'] ?? 40));
            $rows[$index]['incidents_count'] = (int) ($row['incidents_count'] ?? 0);
        }

        return $rows;
    }
}
