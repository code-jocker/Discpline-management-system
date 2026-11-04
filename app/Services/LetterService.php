<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Settings;

final class LetterService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureLettersTable();
    }

    public function generateParentInvitationLetter(
        int $studentId,
        int $term,
        int $score,
        int $threshold,
        string $reason = ''
    ): ?array {
        if ($studentId <= 0) {
            return null;
        }

        $settings = new Settings($this->db);
        if ((string) $settings->get('low_marks_letter_enabled', '1') !== '1') {
            return null;
        }

        $student = $this->fetchStudent($studentId);
        if ($student === null) {
            return null;
        }

        $template = trim((string) $settings->get(
            'low_marks_letter_template',
            "Dear Parent/Guardian,\n\nThis letter is being issued by the school regarding the student's conduct and progress. The current score is {{score}} out of 40, below the threshold of {{threshold}}. The student has the following disciplinary record:\n\n{{disciplinary_history}}\n\nWe kindly request your presence so that we can discuss the situation and agree on appropriate support.\n\nThank you for your cooperation.\n\nSincerely,\nSchool Administration"
        ));

        $disciplinaryHistory = $this->buildDisciplinaryHistory($studentId);
        $body = $this->renderTemplate($template, [
            '{{student_name}}' => (string) ($student['full_name'] ?? ''),
            '{{student_full_name}}' => (string) ($student['full_name'] ?? ''),
            '{{student_code}}' => (string) ($student['student_code'] ?? ''),
            '{{class_name}}' => (string) ($student['class_name'] ?? ''),
            '{{school_name}}' => trim((string) $settings->get('school_name', 'School')),
            '{{threshold}}' => (string) $threshold,
            '{{score}}' => (string) $score,
            '{{term}}' => (string) $term,
            '{{date}}' => date('d M Y'),
            '{{reason}}' => $reason !== '' ? $reason : "the student's recent performance",
            '{{disciplinary_history}}' => $disciplinaryHistory,
        ]);

        $subject = 'Parent Invitation Letter';
        $existing = $this->fetchExistingLetter($studentId, $term);

        if ($existing !== null) {
            if (($existing['source'] ?? 'auto') !== 'auto') {
                return null;
            }

            $unchanged = (int) ($existing['score'] ?? -1) === $score
                && (int) ($existing['threshold'] ?? -1) === $threshold
                && (string) ($existing['subject'] ?? '') === $subject
                && (string) ($existing['body'] ?? '') === $body;

            if ($unchanged) {
                return [
                    'letter_id' => (int) $existing['id'],
                    'created' => false,
                    'updated' => false,
                    'subject' => $subject,
                    'body' => $body,
                ];
            }

            $stmt = $this->db->prepare(
                'UPDATE student_letters
                 SET subject = :subject,
                     score = :score,
                     threshold = :threshold,
                     body = :body,
                     source = :source,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'subject' => $subject,
                'score' => $score,
                'threshold' => $threshold,
                'body' => $body,
                'source' => 'auto',
                'id' => (int) $existing['id'],
            ]);

            return [
                'letter_id' => (int) $existing['id'],
                'created' => false,
                'updated' => true,
                'subject' => $subject,
                'body' => $body,
            ];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO student_letters (student_id, term, score, threshold, subject, body, source)
             VALUES (:student_id, :term, :score, :threshold, :subject, :body, :source)'
        );
        $stmt->execute([
            'student_id' => $studentId,
            'term' => $term,
            'score' => $score,
            'threshold' => $threshold,
            'subject' => $subject,
            'body' => $body,
            'source' => 'auto',
        ]);

        return [
            'letter_id' => (int) $this->db->lastInsertId(),
            'created' => true,
            'updated' => false,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Synchronize parent invitation letters for all students under the threshold
     * in a given term.
     */
    public function syncLowMarksLettersForTerm(int $term, ?int $threshold = null): array
    {
        $settings = new Settings($this->db);
        $term = in_array($term, [1, 2, 3], true) ? $term : (int) $settings->get('current_term', 1);
        $threshold = $threshold ?? (int) $settings->get('low_marks_threshold', '20');
        $threshold = max(0, min(40, $threshold));

        if ((string) $settings->get('low_marks_letter_enabled', '1') !== '1') {
            return [
                'term' => $term,
                'threshold' => $threshold,
                'count' => 0,
                'letters' => [],
                'enabled' => false,
            ];
        }

        if (!\Database::tableExists('student_marks')) {
            return [
                'term' => $term,
                'threshold' => $threshold,
                'count' => 0,
                'letters' => [],
                'enabled' => true,
            ];
        }

        $stmt = $this->db->prepare(
            'SELECT s.id AS student_id, s.full_name, s.student_code, c.name AS class_name, sm.marks AS score
             FROM student_marks sm
             INNER JOIN students s ON s.id = sm.student_id
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE sm.term = :term
               AND sm.marks < :threshold
             ORDER BY sm.marks ASC, s.full_name ASC'
        );
        $stmt->execute([
            'term' => $term,
            'threshold' => $threshold,
        ]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $letters = [];
        foreach ($students as $student) {
            $studentId = (int) ($student['student_id'] ?? 0);
            $score = (int) ($student['score'] ?? 0);
            if ($studentId <= 0) {
                continue;
            }

            $result = $this->generateParentInvitationLetter($studentId, $term, $score, $threshold);
            if ($result === null) {
                continue;
            }

            $letters[] = [
                'student_id' => $studentId,
                'student_name' => (string) ($student['full_name'] ?? ''),
                'student_code' => (string) ($student['student_code'] ?? ''),
                'class_name' => (string) ($student['class_name'] ?? ''),
                'score' => $score,
                'term' => $term,
                'threshold' => $threshold,
                'letter_id' => (int) ($result['letter_id'] ?? 0),
                'created' => (bool) ($result['created'] ?? false),
                'updated' => (bool) ($result['updated'] ?? false),
                'subject' => (string) ($result['subject'] ?? 'Parent Invitation Letter'),
                'body' => (string) ($result['body'] ?? ''),
            ];
        }

        return [
            'term' => $term,
            'threshold' => $threshold,
            'count' => count($letters),
            'letters' => $letters,
            'enabled' => true,
        ];
    }

    public function saveLetter(?int $id, int $studentId, int $term, int $score, int $threshold, string $subject, string $body, string $source = 'manual'): ?int
    {
        if ($studentId <= 0 || trim($subject) === '' || trim($body) === '') {
            return null;
        }

        if ($id !== null && $id > 0) {
            $stmt = $this->db->prepare(
                'UPDATE student_letters
                 SET student_id = :student_id,
                     term = :term,
                     score = :score,
                     threshold = :threshold,
                     subject = :subject,
                     body = :body,
                     source = :source,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'student_id' => $studentId,
                'term' => $term,
                'score' => $score,
                'threshold' => $threshold,
                'subject' => $subject,
                'body' => $body,
                'source' => $source,
                'id' => $id,
            ]);

            return $id;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO student_letters (student_id, term, score, threshold, subject, body, source)
             VALUES (:student_id, :term, :score, :threshold, :subject, :body, :source)'
        );
        $stmt->execute([
            'student_id' => $studentId,
            'term' => $term,
            'score' => $score,
            'threshold' => $threshold,
            'subject' => $subject,
            'body' => $body,
            'source' => $source,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function saveManualLetter(
        int $studentId,
        int $term,
        int $score,
        int $threshold,
        string $subject,
        string $body
    ): ?int {
        $existing = $this->fetchExistingLetter($studentId, $term);
        if ($existing !== null) {
            return $this->saveLetter((int) $existing['id'], $studentId, $term, $score, $threshold, $subject, $body, 'manual');
        }

        return $this->saveLetter(null, $studentId, $term, $score, $threshold, $subject, $body, 'manual');
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM student_letters WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT sl.*, s.full_name, s.student_code, c.name AS class_name
             FROM student_letters sl
             JOIN students s ON s.id = sl.student_id
             LEFT JOIN classes c ON c.id = s.class_id
             ORDER BY sl.generated_at DESC, sl.id DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT sl.*, s.full_name, s.student_code, c.name AS class_name
             FROM student_letters sl
             JOIN students s ON s.id = sl.student_id
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE sl.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function ensureLettersTable(): void
    {
        // CREATE/ALTER statements force an implicit COMMIT in MySQL, which would
        // silently end a caller's in-flight transaction, so only touch the schema
        // when it is actually missing.
        if (\Database::tableExists('student_letters')) {
            if (!\Database::columnExists('student_letters', 'source')) {
                $this->db->exec("ALTER TABLE student_letters ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER body");
            }

            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS student_letters (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_id INT UNSIGNED NOT NULL,
                term INT UNSIGNED NOT NULL DEFAULT 1,
                score INT UNSIGNED NOT NULL DEFAULT 0,
                threshold INT UNSIGNED NOT NULL DEFAULT 20,
                subject VARCHAR(255) DEFAULT NULL,
                body LONGTEXT NOT NULL,
                source VARCHAR(16) NOT NULL DEFAULT 'auto',
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_student_letters_student (student_id),
                INDEX idx_student_letters_term (term),
                CONSTRAINT fk_student_letters_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function renderTemplate(string $template, array $replacements): string
    {
        $normalized = [];
        foreach ($replacements as $placeholder => $value) {
            $normalized[$placeholder] = (string) $value;
        }

        return trim(strtr($template, $normalized));
    }

    private function buildDisciplinaryHistory(int $studentId): string
    {
        $stmt = $this->db->prepare(
            'SELECT term, marks_deducted, reason, timestamp
             FROM deductions_history
             WHERE student_id = :student_id
             ORDER BY timestamp ASC, id ASC'
        );
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return 'No prior disciplinary incidents recorded.';
        }

        $lines = [];
        foreach ($rows as $row) {
            $term = (int) ($row['term'] ?? 1);
            $marks = (int) ($row['marks_deducted'] ?? 0);
            $reason = trim((string) ($row['reason'] ?? ''));
            $timestamp = (string) ($row['timestamp'] ?? '');
            $formattedDate = $timestamp !== '' ? date('d M Y', strtotime($timestamp) ?: time()) : date('d M Y');
            $actionLabel = $marks > 0
                ? sprintf('Deducted %d mark(s)', $marks)
                : sprintf('Rewarded %d mark(s)', abs($marks));
            $reasonText = $reason !== '' ? $reason : 'No reason provided';
            $lines[] = sprintf('- %s (Term %d): %s. Action: %s.', $formattedDate, $term, $reasonText, $actionLabel);
        }

        return implode(PHP_EOL, $lines);
    }

    private function fetchStudent(int $studentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.full_name, s.student_code, c.name AS class_name
             FROM students s
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function fetchExistingLetter(int $studentId, int $term): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, source, subject, body, score, threshold
             FROM student_letters
             WHERE student_id = :student_id AND term = :term
             LIMIT 1'
        );
        $stmt->execute([
            'student_id' => $studentId,
            'term' => $term,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
