<?php
declare(strict_types=1);

namespace App\Services;

use Database;
use PDO;
use Notification;
use Student;
use Throwable;

require_once dirname(__DIR__) . '/Services/AiRiskService.php';
require_once dirname(__DIR__) . '/Services/LetterService.php';
require_once dirname(__DIR__) . '/Services/ParentNotificationService.php';

/**
 * RiskEngineService
 *
 * Orchestrates AI-powered and rule-based risk assessment for students.
 * After assessment, stores results in student_risk_profiles.
 */
final class RiskEngineService
{
    public const LEVEL_CRITICAL   = 'Critical';
    public const LEVEL_WARNING    = 'Warning';
    public const LEVEL_EXCELLENT  = 'Excellent';
    public const LEVEL_HIGH_RISK  = 'High Risk';
    public const LEVEL_MEDIUM_RISK = 'Medium Risk';
    public const LEVEL_LOW_RISK   = 'Low Risk';

    private PDO          $db;
    private AiRiskService $ai; // Assuming AiRiskService is in the same namespace or globally available
    private Notification $notifications;
    private ParentNotificationService $parentNotifications;
    private Student      $students;
    private array        $privilegedRoleNames;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ai = new AiRiskService();
        $this->notifications = new Notification($db);
        $this->parentNotifications = new ParentNotificationService($db);
        $this->students = new Student($db);
        // Resolve privileged role names here, similar to User model
        $configured = getenv('SBMS_PRIVILEGED_ROLE_NAMES') ?: 'admin,headteacher,it,deputy headteacher discipline,deputy headteacher in charge of discipline';
        $this->privilegedRoleNames = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($configured))))));
    }

    /**
     * Assess a single student: builds features → calls AI (with rule fallback) → stores result.
     *
     * @return array{student_id: int, risk_level: string, features: array, model_version: string}|null
     */
    public function assessStudent(int $studentId, ?int $term = null): ?array
    {
        $features = $this->buildFeatures($studentId, $term);
        if ($features === null) {
            return null;
        }

        $riskLevel = $this->classifyByRules($features);
        $modelVersion = 'score-based-v1';

        $threshold = $this->getLowMarksThreshold();

        // Strict policy override for marks below the configured threshold.
        if (isset($features['lowest_mark']) && $features['lowest_mark'] < $threshold) {
            $riskLevel = self::LEVEL_CRITICAL;
            $modelVersion = 'strict-policy-v1';
        }

        $this->storeRiskProfile($studentId, $riskLevel, $features, $modelVersion);
        $this->maybeGenerateParentLetter($studentId, $features, $riskLevel);

        // Send notification when the student reaches the high-risk threshold.
        if (in_array($riskLevel, [self::LEVEL_CRITICAL, self::LEVEL_HIGH_RISK], true)) {
            $student = $this->students->find($studentId);
            if ($student) {
                $notificationMessage = sprintf(
                    'Student %s (%s) has escalated to %s.',
                    $student['full_name'],
                    $student['student_code'],
                    $riskLevel
                );
                $adminUserIds = $this->notifications->findUsersByRolePatterns($this->privilegedRoleNames);
                $this->notifications->createBulk($adminUserIds, $notificationMessage, Notification::PRIORITY_HIGH);
            }
        }

        return [
            'student_id'    => $studentId,
            'risk_level'    => $riskLevel,
            'features'      => $features,
            'model_version' => $modelVersion,
        ];
    }

    /**
     * Assess every student in the database (or scoped to a single tenant).
     *
     * @return list<array>
     */
    public function assessAllStudents(?int $tenantId = null, ?int $term = null): array
    {
        if ($tenantId !== null) {
            $stmt = $this->db->prepare('SELECT id FROM students WHERE tenant_id = :tenant_id');
            $stmt->execute(['tenant_id' => $tenantId]);
        } else {
            $stmt = $this->db->query('SELECT id FROM students');
        }

        $studentIds = array_column($stmt->fetchAll(), 'id');
        $results    = [];

        foreach ($studentIds as $studentId) {
            try {
                $result = $this->assessStudent((int) $studentId, $term);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (Throwable $e) {
                Database::logError('Risk assessment failed for student', [
                    'student_id' => $studentId,
                    'exception'  => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Retrieve the stored risk profile for a student (or null if not yet assessed).
     */
    public function getStoredProfile(int $studentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT risk_level, incidents_count, total_marks_deducted,
                    avg_deduction_per_week, model_version, predicted_at
             FROM   student_risk_profiles
             WHERE  student_id = :student_id
             LIMIT  1'
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Aggregate discipline data into ML features.
     *
     * @return array{incidents: int, total_marks_deducted: int, avg_per_week: float}|null
     */
    private function buildFeatures(int $studentId, ?int $term = null): ?array
    {
        if ($this->students->find($studentId) === null) {
            return null;
        }

        $markRow = null;
        $resolvedTerm = $term ?? $this->getCurrentTerm();

        if ($resolvedTerm !== null) {
            $stmtMarks = $this->db->prepare(
                'SELECT term, marks AS current_score
                 FROM student_marks
                 WHERE student_id = :student_id AND term = :term
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmtMarks->execute([
                'student_id' => $studentId,
                'term' => $resolvedTerm,
            ]);
            $markRow = $stmtMarks->fetch() ?: null;
        }

        if ($markRow === null) {
            $stmtMarks = $this->db->prepare(
                'SELECT term, marks AS current_score
                 FROM student_marks
                 WHERE student_id = :student_id
                 ORDER BY term DESC, id DESC
                 LIMIT 1'
            );
            $stmtMarks->execute(['student_id' => $studentId]);
            $markRow = $stmtMarks->fetch() ?: null;
        }

        $term = (int) ($markRow['term'] ?? 1);
        $currentScore = max(0, min(40, (int) (($markRow['current_score'] ?? 40))));

        $stmt = $this->db->prepare(
            'SELECT COUNT(CASE WHEN marks_deducted > 0 THEN 1 END) AS incidents,
                    COALESCE(SUM(CASE WHEN marks_deducted > 0 THEN marks_deducted ELSE 0 END), 0) AS total_positive_deductions,
                    COALESCE(SUM(CASE WHEN marks_deducted < 0 THEN ABS(marks_deducted) ELSE 0 END), 0) AS total_rewards,
                    MIN(CASE WHEN marks_deducted > 0 THEN `timestamp` END) AS first_incident,
                    MAX(CASE WHEN marks_deducted > 0 THEN `timestamp` END) AS last_incident
             FROM   deductions_history
             WHERE  student_id = :student_id'
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();

        $stmtLowest = $this->db->prepare(
            'SELECT MIN(marks) AS lowest_marks
             FROM student_marks
             WHERE student_id = :student_id'
        );
        $stmtLowest->execute(['student_id' => $studentId]);
        $lowestRow = $stmtLowest->fetch();
        $lowestMark = ($lowestRow['lowest_marks'] === null || $lowestRow['lowest_marks'] === false)
            ? $currentScore
            : (int) $lowestRow['lowest_marks'];

        if (!$row) {
            return [
                'incidents' => 0,
                'total_marks_deducted' => 0,
                'total_rewards' => 0,
                'avg_per_week' => 0.0,
                'lowest_mark' => $lowestMark,
                'current_score' => $currentScore,
                'term' => $term,
            ];
        }

        $incidents     = (int) $row['incidents'];
        $totalRewards  = (int) $row['total_rewards'];
        $totalDeducted = max(0, (int) $row['total_positive_deductions'] - $totalRewards);
        $avgPerWeek    = 0.0;

        if ($incidents > 0 && !empty($row['first_incident']) && !empty($row['last_incident'])) {
            $firstTs = strtotime((string) $row['first_incident']);
            $lastTs  = strtotime((string) $row['last_incident']);
            $weeks   = max(1, ceil(($lastTs - $firstTs) / 604800));
            $avgPerWeek = round($totalDeducted / $weeks, 2);
        }

        return [
            'incidents'            => $incidents,
            'total_marks_deducted' => $totalDeducted,
            'total_rewards'        => $totalRewards,
            'avg_per_week'         => $avgPerWeek,
            'lowest_mark'          => $lowestMark,
            'current_score'        => $currentScore,
            'term'                 => $term,
        ];
    }

    /**
     * Threshold-based rule classification — runs when AI service is unavailable.
     */
    private function classifyByRules(array $features): string
    {
        if (isset($features['current_score'])) {
            $score = max(0, min(40, (int) $features['current_score']));
            if ($score <= 19) {
                return self::LEVEL_HIGH_RISK;
            }
            if ($score <= 29) {
                return self::LEVEL_MEDIUM_RISK;
            }
            return self::LEVEL_LOW_RISK;
        }

        $threshold = $this->getLowMarksThreshold();
        if (isset($features['lowest_mark']) && $features['lowest_mark'] < $threshold) {
            return self::LEVEL_CRITICAL;
        }

        $i = $features['incidents'];
        $t = $features['total_marks_deducted'];
        $a = $features['avg_per_week'];

        if ($i >= 8 || $t >= 40 || $a >= 4.0) {
            return self::LEVEL_CRITICAL;
        }

        if ($i >= 3 || $t >= 15 || $a >= 1.5) {
            return self::LEVEL_WARNING;
        }

        return self::LEVEL_EXCELLENT;
    }

    private function maybeGenerateParentLetter(int $studentId, array $features, string $riskLevel): void
    {
        $threshold = $this->getLowMarksThreshold();
        $score = max(0, min(40, (int) ($features['current_score'] ?? 40)));

        if ($score >= $threshold) {
            return;
        }

        try {
            $letterService = new \App\Services\LetterService($this->db);
            $letterResult = $letterService->generateParentInvitationLetter(
                $studentId,
                (int) ($features['term'] ?? 1),
                $score,
                $threshold,
                'Behavioral and academic follow-up due to score below threshold.'
            );

            if ($letterResult === null || !($letterResult['created'] || $letterResult['updated'])) {
                return;
            }

            $student = $this->students->find($studentId);
            if ($student) {
                $notificationMessage = sprintf(
                    'Parent invitation letter generated for %s (%s) for term %d. Score: %d/%d.',
                    $student['full_name'],
                    $student['student_code'],
                    (int) ($features['term'] ?? 1),
                    $score,
                    $threshold
                );

                $recipientIds = $this->notifications->findUsersByRolePatterns([
                    '%headteacher%',
                    '%discipline%',
                    '%admin%'
                ]);

                $this->notifications->createBulk($recipientIds, $notificationMessage, Notification::PRIORITY_MEDIUM);
            }

            $this->parentNotifications->notifyParentsOfLetter(
                $studentId,
                (string) ($letterResult['subject'] ?? 'Parent Invitation Letter'),
                (string) ($letterResult['body'] ?? '')
            );
        } catch (\Throwable $exception) {
            Database::logError('Parent letter generation failed', [
                'student_id' => $studentId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function getLowMarksThreshold(): int
    {
        $settings = new \Settings($this->db);
        $threshold = (int) $settings->get('low_marks_threshold', '20');

        return max(0, min(40, $threshold));
    }

    private function getCurrentTerm(): ?int
    {
        try {
            $settings = new \Settings($this->db);
            $term = (int) $settings->get('current_term', 1);
            return in_array($term, [1, 2, 3], true) ? $term : null;
        } catch (Throwable $exception) {
            Database::logError('Current term lookup failed', ['exception' => $exception->getMessage()]);
            return null;
        }
    }

    /**
     * Upsert the risk profile record.
     */
    private function storeRiskProfile(
        int    $studentId,
        string $riskLevel,
        array  $features,
        string $modelVersion
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO student_risk_profiles
                 (student_id, risk_level, incidents_count, total_marks_deducted,
                  avg_deduction_per_week, model_version, predicted_at)
             VALUES
                 (:student_id, :risk_level, :incidents, :total_deducted,
                  :avg_per_week, :model_version, NOW())
             ON DUPLICATE KEY UPDATE
                 risk_level             = VALUES(risk_level),
                 incidents_count        = VALUES(incidents_count),
                 total_marks_deducted   = VALUES(total_marks_deducted),
                 avg_deduction_per_week = VALUES(avg_deduction_per_week),
                 model_version          = VALUES(model_version),
                 predicted_at           = VALUES(predicted_at),
                 updated_at             = NOW()'
        );
        $stmt->execute([
            'student_id'    => $studentId,
            'risk_level'    => $riskLevel,
            'incidents'     => $features['incidents'],
            'total_deducted'=> $features['total_marks_deducted'],
            'avg_per_week'  => $features['avg_per_week'],
            'model_version' => $modelVersion,
        ]);
    }
}
