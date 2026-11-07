<?php

namespace App\Services;

use Database;
use PDO;
use DateTime;
use Throwable;

/**
 * ParentNotificationService - Manages parent/guardian communications.
 * Supports: Email (PHP mail fallback) and WhatsApp via durable queue + whatsapp-web.js worker (no Twilio).
 */
class ParentNotificationService
{
    private PDO $pdo;
    private string $emailFrom;

    public function __construct(PDO $pdo, string $emailFrom = 'noreply@sbms.local')
    {
        $this->pdo = $pdo;
        $this->emailFrom = $emailFrom;
        $this->ensureParentContactsTable();
    }

    public function registerParent(
        int $studentId,
        string $parentName,
        string $relationship,
        ?string $email,
        ?string $phone,
        ?string $whatsapp,
        string $preference = 'email',
        bool $isPrimary = false
    ): ?int {
        return $this->saveParentContact([
            'student_id' => $studentId,
            'parent_name' => $parentName,
            'relationship' => $relationship,
            'email' => $email,
            'phone_number' => $phone,
            'whatsapp_number' => $whatsapp,
            'contact_preference' => $preference,
            'is_primary' => $isPrimary ? 1 : 0,
        ]);
    }

    public function listParentContacts(?int $studentId = null): array
    {
        try {
            $sql = "SELECT pc.id, pc.student_id, pc.parent_name, pc.relationship, pc.email,
                           pc.phone_number, pc.whatsapp_number, pc.contact_preference, pc.is_primary,
                           pc.created_at,
                           s.full_name AS student_name, s.student_code, c.name AS class_name
                    FROM parent_contacts pc
                    INNER JOIN students s ON s.id = pc.student_id
                    LEFT JOIN classes c ON c.id = s.class_id";
            $params = [];
            if ($studentId !== null) {
                $sql .= ' WHERE pc.student_id = :student_id';
                $params['student_id'] = $studentId;
            }
            $sql .= ' ORDER BY pc.is_primary DESC, s.full_name ASC, pc.parent_name ASC, pc.id ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Error fetching parent contacts list: ' . $e->getMessage());
            return [];
        }
    }

    public function findParentContactById(int $contactId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT pc.id, pc.student_id, pc.parent_name, pc.relationship, pc.email,
                        pc.phone_number, pc.whatsapp_number, pc.contact_preference, pc.is_primary,
                        pc.created_at,
                        s.full_name AS student_name, s.student_code, c.name AS class_name
                 FROM parent_contacts pc
                 INNER JOIN students s ON s.id = pc.student_id
                 LEFT JOIN classes c ON c.id = s.class_id
                 WHERE pc.id = ? LIMIT 1"
            );
            $stmt->execute([$contactId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('Error fetching parent contact: ' . $e->getMessage());
            return null;
        }
    }

    public function saveParentContact(array $data): ?int
    {
        $studentId = (int) ($data['student_id'] ?? 0);
        $parentName = trim((string) ($data['parent_name'] ?? ''));
        $relationship = trim((string) ($data['relationship'] ?? ''));
        $email = trim((string) ($data['email'] ?? '')) ?: null;
        $phone = trim((string) ($data['phone_number'] ?? '')) ?: null;
        $whatsapp = trim((string) ($data['whatsapp_number'] ?? '')) ?: null;
        $preference = strtolower(trim((string) ($data['contact_preference'] ?? 'email')));
        $isPrimary = !empty($data['is_primary']) ? 1 : 0;
        $contactId = isset($data['id']) && ctype_digit((string) $data['id']) ? (int) $data['id'] : 0;

        if ($studentId <= 0 || $parentName === '') {
            return null;
        }
        if (!in_array($preference, ['email', 'sms', 'whatsapp'], true)) {
            $preference = 'email';
        }

        try {
            $this->pdo->beginTransaction();
            if ($isPrimary === 1) {
                $stmt = $this->pdo->prepare('UPDATE parent_contacts SET is_primary = 0 WHERE student_id = ?');
                $stmt->execute([$studentId]);
            }
            if ($contactId > 0) {
                $stmt = $this->pdo->prepare(
                    'UPDATE parent_contacts SET student_id=:student_id,parent_name=:parent_name,relationship=:relationship,
                     email=:email,phone_number=:phone_number,whatsapp_number=:whatsapp_number,
                     contact_preference=:contact_preference,is_primary=:is_primary WHERE id=:id'
                );
                $stmt->execute([
                    'student_id' => $studentId, 'parent_name' => $parentName, 'relationship' => $relationship,
                    'email' => $email, 'phone_number' => $phone, 'whatsapp_number' => $whatsapp,
                    'contact_preference' => $preference, 'is_primary' => $isPrimary, 'id' => $contactId,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO parent_contacts (student_id,parent_name,relationship,email,phone_number,whatsapp_number,contact_preference,is_primary)
                     VALUES (:student_id,:parent_name,:relationship,:email,:phone_number,:whatsapp_number,:contact_preference,:is_primary)'
                );
                $stmt->execute([
                    'student_id' => $studentId, 'parent_name' => $parentName, 'relationship' => $relationship,
                    'email' => $email, 'phone_number' => $phone, 'whatsapp_number' => $whatsapp,
                    'contact_preference' => $preference, 'is_primary' => $isPrimary,
                ]);
                $contactId = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->commit();
            return $contactId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Parent contact save error: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteParentContact(int $contactId): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM parent_contacts WHERE id = ?');
            return $stmt->execute([$contactId]);
        } catch (Throwable $e) {
            error_log('Parent contact delete error: ' . $e->getMessage());
            return false;
        }
    }

    public function notifyParentsOfIncident(int $studentId, int $deductionId, string $reason, int $marksDeducted): bool
    {
        try {
            $student = $this->getStudent($studentId);
            if (!$student) return false;
            $parents = $this->getParentContacts($studentId);
            if (empty($parents)) return false;
            $subject = "Alert: {$student['full_name']} - Behavioral Incident";
            $message = $this->generateIncidentMessage($student, $reason, $marksDeducted);
            foreach ($parents as $parent) {
                $this->sendNotification($parent, $subject, $message, 'incident_alert', $deductionId);
            }
            return true;
        } catch (Throwable $e) {
            error_log('Parent incident notification error: ' . $e->getMessage());
            return false;
        }
    }

    public function notifyParentsOfAchievement(int $studentId, string $achievementTitle): bool
    {
        try {
            $student = $this->getStudent($studentId);
            if (!$student) return false;
            $parents = $this->getParentContacts($studentId);
            if (empty($parents)) return false;
            $subject = "Great News: {$student['full_name']} Achievement";
            $message = "Hello, {$student['full_name']} has achieved: {$achievementTitle}. Keep up the great work! - School Administration";
            foreach ($parents as $parent) {
                $this->sendNotification($parent, $subject, $message, 'achievement');
            }
            return true;
        } catch (Throwable $e) {
            error_log('Parent achievement notification error: ' . $e->getMessage());
            return false;
        }
    }

    public function sendReportToParents(int $studentId, int $reportFileId, string $reportType): bool
    {
        try {
            $student = $this->getStudent($studentId);
            $parents = $this->getParentContacts($studentId);
            if (!$student || empty($parents)) return false;
            $subject = "{$student['full_name']} - {$reportType} Report";
            $message = "Dear Parent/Guardian, the {$reportType} for {$student['full_name']} is now available. Please log in to the parent portal to view it. - School Administration";
            foreach ($parents as $parent) {
                $this->sendNotification($parent, $subject, $message, 'report', null, $reportFileId);
            }
            return true;
        } catch (Throwable $e) {
            error_log('Report parent notification error: ' . $e->getMessage());
            return false;
        }
    }

    public function notifyParentsOfLetter(int $studentId, string $subject, string $message): bool
    {
        try {
            $student = $this->getStudent($studentId);
            if (!$student) return false;
            $parents = $this->getParentContacts($studentId);
            if (empty($parents)) return false;
            foreach ($parents as $parent) {
                $this->sendNotification($parent, $subject, $message, 'letter');
            }
            return true;
        } catch (Throwable $e) {
            error_log('Parent letter notification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue a WhatsApp test message (no direct send, no Twilio).
     */
    public function sendWhatsAppTestMessageToContact(int $contactId, string $message): array
    {
        try {
            $contact = $this->findParentContactById($contactId);
            if (!$contact) {
                return ['success' => false, 'message' => 'Selected parent contact was not found.'];
            }
            $whatsappNumber = trim((string) ($contact['whatsapp_number'] ?? ''));
            if ($whatsappNumber === '') {
                return ['success' => false, 'message' => 'The selected contact does not have a WhatsApp number saved.'];
            }
            $message = trim($message);
            if ($message === '') {
                $message = 'Hello from SBMS. This is a test WhatsApp message.';
            }

            $queue = new WhatsAppQueueService($this->pdo);
            if (!$queue->isAvailable()) {
                return ['success' => false, 'message' => 'WhatsApp queue is not available. Run the migration and start the worker.', 'contact' => $contact];
            }
            $id = $queue->enqueue(!empty($contact['student_id']) ? (int) $contact['student_id'] : null, $whatsappNumber, $message, 'whatsapp_test', 'whatsapp_test:contact-' . $contactId . ':' . time());
            if ($id === null) {
                return ['success' => false, 'message' => 'Unable to queue the test WhatsApp message. Check worker/queue settings.', 'contact' => $contact];
            }

            $this->logParentNotification($contact, 'whatsapp_test', 'WhatsApp Test', $message, 'whatsapp_queue', 'pending');
            return [
                'success' => true,
                'message' => 'Test WhatsApp queued for ' . ($contact['parent_name'] ?? 'contact') . ' (job #' . $id . '). The worker will send it when online.',
                'contact' => $contact,
                'queue_id' => $id,
            ];
        } catch (Throwable $e) {
            error_log('WhatsApp test queue error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to queue the test WhatsApp message.'];
        }
    }

    public function sendWhatsAppTestMessageToFirstContact(string $message): array
    {
        $contact = $this->findFirstParentContactWithChannel('whatsapp_number');
        if (!$contact) {
            return ['success' => false, 'message' => 'No parent contacts with WhatsApp numbers were found.'];
        }
        return $this->sendWhatsAppTestMessageToContact((int) $contact['id'], $message);
    }

    /**
     * SMS test is disabled (Twilio removed). Kept for compatibility.
     */
    public function sendSmsTestMessageToContact(int $contactId, string $message): array
    {
        return ['success' => false, 'message' => 'SMS via Twilio has been removed. Use WhatsApp Web queue or email.'];
    }

    public function getParentContacts(int $studentId): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, student_id, parent_name, email, phone_number, whatsapp_number, contact_preference FROM parent_contacts WHERE student_id = ? ORDER BY is_primary DESC");
            $stmt->execute([$studentId]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($contacts !== []) return $contacts;

            $student = $this->getStudent($studentId);
            if (!$student) return [];
            $fallbackNumber = trim((string) ($student['parent_contact'] ?? ''));
            if ($fallbackNumber === '') return [];
            return [[
                'id' => 0, 'student_id' => $studentId,
                'parent_name' => $student['full_name'] . ' Parent/Guardian',
                'email' => null, 'phone_number' => $fallbackNumber,
                'whatsapp_number' => $fallbackNumber, 'contact_preference' => 'whatsapp',
            ]];
        } catch (Throwable $e) {
            error_log('Error fetching parent contacts: ' . $e->getMessage());
            return [];
        }
    }

    private function sendNotification(
        array $parent,
        string $subject,
        string $message,
        string $type,
        ?int $deductionId = null,
        ?int $fileId = null
    ): bool {
        try {
            $method = $parent['contact_preference'] ?? 'email';
            $delivered = false;
            $queued = null;

            if ($method === 'whatsapp' && !empty($parent['whatsapp_number'])) {
                $queue = new WhatsAppQueueService($this->pdo);
                if ($queue->isAvailable() && $queue->isEnabled()) {
                    $dedupeKey = $deductionId !== null
                        ? $type . ':deduction-' . $deductionId . ':contact-' . (int) ($parent['id'] ?? 0)
                        : $type . ':student-' . (int) ($parent['student_id'] ?? 0) . ':contact-' . (int) ($parent['id'] ?? 0) . ':' . md5($message);
                    $queued = $queue->enqueue(
                        !empty($parent['student_id']) ? (int) $parent['student_id'] : null,
                        (string) $parent['whatsapp_number'],
                        $message,
                        $type,
                        $dedupeKey
                    );
                    if ($queued !== null) {
                        $delivered = true;
                        $method = 'whatsapp_queue';
                    }
                }
                if ($queued === null) {
                    $delivered = false;
                    $method = 'whatsapp';
                }
            } elseif ($method === 'email' && !empty($parent['email'])) {
                $delivered = $this->sendEmail($parent['email'], $subject, $this->wrapHtml($message));
            } elseif ($method === 'sms' && !empty($parent['phone_number'])) {
                $delivered = false; // Twilio removed
                error_log('SMS delivery disabled: Twilio removed; use WhatsApp Web queue or email.');
            }

            $status = $queued !== null ? 'pending' : ($delivered ? 'sent' : 'failed');
            $this->logParentNotification($parent, $type, $subject, $message, $method, $status);
            return $delivered;
        } catch (Throwable $e) {
            error_log('Send notification error: ' . $e->getMessage());
            return false;
        }
    }

    private function sendEmail(string $to, string $subject, string $htmlContent): bool
    {
        try {
            $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: {$this->emailFrom}\r\n";
            return @mail($to, $subject, $htmlContent, $headers);
        } catch (Throwable $e) {
            error_log('Email send error: ' . $e->getMessage());
            return false;
        }
    }

    private function wrapHtml(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $safe = nl2br($safe);
        return "<html><body style='font-family:Arial,sans-serif;color:#333'><p>{$safe}</p><p>— School Administration</p></body></html>";
    }

    private function generateIncidentMessage(array $student, string $reason, int $marksDeducted): string
    {
        $currentMarks = 40 - $marksDeducted;
        return "Dear Parent/Guardian, {$student['full_name']} had a behavioral incident.\nReason: {$reason}\nMarks deducted: {$marksDeducted}\nCurrent marks: {$currentMarks}/40\nPlease discuss this with your child. - School Administration";
    }

    private function logParentNotification(array $parent, string $type, string $subject, string $message, string $method, string $status): void
    {
        try {
            if (!Database::tableExists('parent_notifications_log')) {
                return;
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO parent_notifications_log (parent_contact_id, student_id, notification_type, subject, message_body, delivery_method, delivery_status, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $parent['id'] ?? null,
                $parent['student_id'] ?? null,
                $type,
                $subject,
                $message,
                $method,
                $status,
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    private function getStudent(int $studentId): ?array
    {
        try {
            $parentContactSelect = Database::columnExists('students', 'parent_contact') ? ', parent_contact' : '';
            $stmt = $this->pdo->prepare("SELECT id, full_name, student_code, class_id{$parentContactSelect} FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('Error fetching student: ' . $e->getMessage());
            return null;
        }
    }

    private function findFirstParentContactWithChannel(string $numberField): ?array
    {
        if (!in_array($numberField, ['phone_number', 'whatsapp_number'], true)) {
            return null;
        }
        try {
            $sql = "SELECT pc.id, pc.student_id, pc.parent_name, pc.email, pc.phone_number, pc.whatsapp_number, pc.contact_preference,
                           s.full_name AS student_name, s.student_code, c.name AS class_name
                    FROM parent_contacts pc
                    INNER JOIN students s ON s.id = pc.student_id
                    LEFT JOIN classes c ON c.id = s.class_id
                    WHERE NULLIF(TRIM(pc.$numberField), '') IS NOT NULL
                    ORDER BY pc.is_primary DESC, pc.id ASC LIMIT 1";
            $stmt = $this->pdo->query($sql);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            return $row ?: null;
        } catch (Throwable $e) {
            error_log("Error finding first parent contact with {$numberField}: " . $e->getMessage());
            return null;
        }
    }

    private function ensureParentContactsTable(): void
    {
        if (Database::tableExists('parent_contacts')) {
            return;
        }
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS parent_contacts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    student_id INT UNSIGNED NOT NULL,
                    parent_name VARCHAR(150) NOT NULL,
                    relationship VARCHAR(30) DEFAULT NULL,
                    email VARCHAR(180) DEFAULT NULL,
                    phone_number VARCHAR(20) DEFAULT NULL,
                    whatsapp_number VARCHAR(20) DEFAULT NULL,
                    is_primary TINYINT(1) DEFAULT 0,
                    contact_preference VARCHAR(30) DEFAULT 'email',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_parent_student (student_id),
                    CONSTRAINT fk_parent_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            // DDL can fail in restricted envs; log and continue
        }
    }
}
