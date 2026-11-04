<?php

namespace App\Services;

use Settings;
use PDO;
use DateTime;

/**
 * ParentNotificationService - Manages parent/guardian communications
 * Handles incident alerts, achievement notifications, and parent portal
 * Supports: Email, SMS, WhatsApp (via Twilio)
 */
class ParentNotificationService
{
    private PDO $pdo;
    private string $emailSmtpHost;
    private int $emailSmtpPort;
    private string $emailFrom;
    
    // Twilio credentials
    private ?string $twilioAccountSid;
    private ?string $twilioAuthToken;
    private ?string $twilioPhoneNumber;
    private ?string $twilioWhatsAppNumber;

    public function __construct(PDO $pdo, string $emailFrom = 'noreply@sbms.local')
    {
        $this->pdo = $pdo;
        $this->emailFrom = $emailFrom;
        $this->emailSmtpHost = getenv('MAIL_HOST') ?: 'localhost';
        $this->emailSmtpPort = (int)(getenv('MAIL_PORT') ?: 1025);

        $settings = new Settings($pdo);
        $this->twilioAccountSid = $this->resolveTwilioValue('TWILIO_ACCOUNT_SID', $settings, 'twilio_account_sid');
        $this->twilioAuthToken = $this->resolveTwilioValue('TWILIO_AUTH_TOKEN', $settings, 'twilio_auth_token');
        $this->twilioPhoneNumber = $this->resolveTwilioValue('TWILIO_PHONE_NUMBER', $settings, 'twilio_phone_number');
        $this->twilioWhatsAppNumber = $this->resolveTwilioValue('TWILIO_WHATSAPP_NUMBER', $settings, 'twilio_whatsapp_number');

        $this->ensureParentContactsTable();
    }

    private function resolveTwilioValue(string $envKey, Settings $settings, string $settingKey): ?string
    {
        $envValue = trim((string) (getenv($envKey) ?: ''));
        if ($envValue !== '') {
            return $envValue;
        }

        $settingValue = trim((string) $settings->get($settingKey, ''));
        return $settingValue !== '' ? $settingValue : null;
    }

    /**
     * Register/update parent contact information
     */
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
            $sql = "
                SELECT pc.id, pc.student_id, pc.parent_name, pc.relationship, pc.email,
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
        } catch (\Exception $e) {
            error_log("Error fetching parent contacts list: " . $e->getMessage());
            return [];
        }
    }

    public function findParentContactById(int $contactId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT pc.id, pc.student_id, pc.parent_name, pc.relationship, pc.email,
                       pc.phone_number, pc.whatsapp_number, pc.contact_preference, pc.is_primary,
                       pc.created_at,
                       s.full_name AS student_name, s.student_code, c.name AS class_name
                FROM parent_contacts pc
                INNER JOIN students s ON s.id = pc.student_id
                LEFT JOIN classes c ON c.id = s.class_id
                WHERE pc.id = ?
                LIMIT 1
            ");
            $stmt->execute([$contactId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\Exception $e) {
            error_log("Error fetching parent contact: " . $e->getMessage());
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
                    'UPDATE parent_contacts
                     SET student_id = :student_id,
                         parent_name = :parent_name,
                         relationship = :relationship,
                         email = :email,
                         phone_number = :phone_number,
                         whatsapp_number = :whatsapp_number,
                         contact_preference = :contact_preference,
                         is_primary = :is_primary
                     WHERE id = :id'
                );
                $stmt->execute([
                    'student_id' => $studentId,
                    'parent_name' => $parentName,
                    'relationship' => $relationship,
                    'email' => $email,
                    'phone_number' => $phone,
                    'whatsapp_number' => $whatsapp,
                    'contact_preference' => $preference,
                    'is_primary' => $isPrimary,
                    'id' => $contactId,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO parent_contacts
                     (student_id, parent_name, relationship, email, phone_number, whatsapp_number, contact_preference, is_primary)
                     VALUES (:student_id, :parent_name, :relationship, :email, :phone_number, :whatsapp_number, :contact_preference, :is_primary)'
                );
                $stmt->execute([
                    'student_id' => $studentId,
                    'parent_name' => $parentName,
                    'relationship' => $relationship,
                    'email' => $email,
                    'phone_number' => $phone,
                    'whatsapp_number' => $whatsapp,
                    'contact_preference' => $preference,
                    'is_primary' => $isPrimary,
                ]);
                $contactId = (int) $this->pdo->lastInsertId();
            }

            $this->pdo->commit();
            return $contactId;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log("Parent contact save error: " . $e->getMessage());
            return null;
        }
    }

    public function deleteParentContact(int $contactId): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM parent_contacts WHERE id = ?');
            return $stmt->execute([$contactId]);
        } catch (\Exception $e) {
            error_log("Parent contact delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify parents of incident
     */
    public function notifyParentsOfIncident(int $studentId, int $deductionId, string $reason, int $marksDeducted): bool
    {
        try {
            $student = $this->getStudent($studentId);
            if (!$student) return false;

            $parents = $this->getParentContacts($studentId);
            if (empty($parents)) return false;

            $subject = "Alert: {$student['full_name']} - Behavioral Incident";
            $message = $this->generateIncidentNotification($student, $reason, $marksDeducted);

            foreach ($parents as $parent) {
                $this->sendNotification($parent, $subject, $message, 'incident_alert', $deductionId);
            }

            return true;
        } catch (\Exception $e) {
            error_log("Parent incident notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify parents of achievement
     */
    public function notifyParentsOfAchievement(int $studentId, string $achievementTitle): bool
    {
        try {
            $student = $this->getStudent($studentId);
            if (!$student) return false;

            $parents = $this->getParentContacts($studentId);
            if (empty($parents)) return false;

            $subject = "Great News: {$student['full_name']} Achievement";
            $message = $this->generateAchievementNotification($student, $achievementTitle);

            foreach ($parents as $parent) {
                $this->sendNotification($parent, $subject, $message, 'achievement');
            }

            return true;
        } catch (\Exception $e) {
            error_log("Parent achievement notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send report to parents
     */
    public function sendReportToParents(int $studentId, int $reportFileId, string $reportType): bool
    {
        try {
            $student = $this->getStudent($studentId);
            $parents = $this->getParentContacts($studentId);

            if (!$student || empty($parents)) return false;

            $subject = "{$student['full_name']} - {$reportType} Report";
            $message = $this->generateReportNotification($student, $reportType);

            foreach ($parents as $parent) {
                $this->sendNotification(
                    $parent,
                    $subject,
                    $message,
                    'report',
                    null,
                    $reportFileId
                );
            }

            return true;
        } catch (\Exception $e) {
            error_log("Report parent notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an auto-generated letter to parents.
     */
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
        } catch (\Exception $e) {
            error_log("Parent letter notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a WhatsApp test message to one selected parent contact.
     */
    public function sendWhatsAppTestMessageToContact(int $contactId, string $message): array
    {
        try {
            $contact = $this->findParentContactById($contactId);
            if (!$contact) {
                return [
                    'success' => false,
                    'message' => 'Selected parent contact was not found.',
                ];
            }

            $whatsappNumber = trim((string) ($contact['whatsapp_number'] ?? ''));
            if ($whatsappNumber === '') {
                return [
                    'success' => false,
                    'message' => 'The selected contact does not have a WhatsApp number saved.',
                ];
            }

            $message = trim($message);
            if ($message === '') {
                $message = 'Hello from SBMS. This is a test WhatsApp message.';
            }

            $delivery = $this->sendWhatsAppMessageDirect($whatsappNumber, $message);
            $deliveryMethod = 'whatsapp';
            $notificationType = 'whatsapp_test';
            $status = $delivery['success'] ? 'sent' : 'failed';

            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO parent_notifications_log
                    (parent_contact_id, student_id, notification_type, subject, message_body, delivery_method, delivery_status, sent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $contact['id'],
                    $contact['student_id'] ?? null,
                    $notificationType,
                    'WhatsApp Test',
                    $message,
                    $deliveryMethod,
                    $status,
                ]);
            } catch (\Exception $logException) {
                error_log("WhatsApp test log error: " . $logException->getMessage());
            }

            return [
                'success' => $delivery['success'],
                'message' => $delivery['success']
                    ? 'Test WhatsApp sent successfully to ' . ($contact['parent_name'] ?? 'the selected contact') . '.'
                    : ($delivery['error'] ?? 'Unable to send the test WhatsApp message.'),
                'contact' => $contact,
                'to' => $delivery['to'] ?? null,
                'http_code' => $delivery['http_code'] ?? null,
                'response' => $delivery['response'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log("WhatsApp test send error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Unable to send the test WhatsApp message.',
            ];
        }
    }

    /**
     * Send a WhatsApp test message to the first contact with a saved WhatsApp number.
     */
    public function sendWhatsAppTestMessageToFirstContact(string $message): array
    {
        $contact = $this->findFirstParentContactWithChannel('whatsapp_number');
        if (!$contact) {
            return [
                'success' => false,
                'message' => 'No parent contacts with WhatsApp numbers were found.',
            ];
        }

        return $this->sendWhatsAppTestMessageToContact((int) $contact['id'], $message);
    }

    /**
     * Send an SMS test message to one selected parent contact.
     */
    public function sendSmsTestMessageToContact(int $contactId, string $message): array
    {
        try {
            $contact = $this->findParentContactById($contactId);
            if (!$contact) {
                return [
                    'success' => false,
                    'message' => 'Selected parent contact was not found.',
                ];
            }

            $phoneNumber = trim((string) ($contact['phone_number'] ?? ''));
            if ($phoneNumber === '') {
                return [
                    'success' => false,
                    'message' => 'The selected contact does not have a phone number saved.',
                ];
            }

            $message = trim($message);
            if ($message === '') {
                $message = 'Hello from SBMS. This is a test SMS message.';
            }

            $delivery = $this->sendSmsMessageDirect($phoneNumber, $message);
            $deliveryMethod = 'sms';
            $notificationType = 'sms_test';
            $status = $delivery['success'] ? 'sent' : 'failed';

            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO parent_notifications_log
                    (parent_contact_id, student_id, notification_type, subject, message_body, delivery_method, delivery_status, sent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $contact['id'],
                    $contact['student_id'] ?? null,
                    $notificationType,
                    'SMS Test',
                    $message,
                    $deliveryMethod,
                    $status,
                ]);
            } catch (\Exception $logException) {
                error_log("SMS test log error: " . $logException->getMessage());
            }

            return [
                'success' => $delivery['success'],
                'message' => $delivery['success']
                    ? 'Test SMS sent successfully to ' . ($contact['parent_name'] ?? 'the selected contact') . '.'
                    : ($delivery['error'] ?? 'Unable to send the test SMS message.'),
                'contact' => $contact,
                'to' => $delivery['to'] ?? null,
                'http_code' => $delivery['http_code'] ?? null,
                'response' => $delivery['response'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log("SMS test send error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Unable to send the test SMS message.',
            ];
        }
    }

    /**
     * Get parent contacts for student
     */
    public function getParentContacts(int $studentId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, student_id, parent_name, email, phone_number, whatsapp_number, contact_preference
                FROM parent_contacts
                WHERE student_id = ?
                ORDER BY is_primary DESC
            ");
            $stmt->execute([$studentId]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($contacts !== []) {
                return $contacts;
            }

            $student = $this->getStudent($studentId);
            if (!$student) {
                return [];
            }

            $fallbackNumber = trim((string) ($student['parent_contact'] ?? ''));
            if ($fallbackNumber === '') {
                return [];
            }

            return [[
                'id' => 0,
                'student_id' => $studentId,
                'parent_name' => $student['full_name'] . ' Parent/Guardian',
                'email' => null,
                'phone_number' => $fallbackNumber,
                'whatsapp_number' => $fallbackNumber,
                'contact_preference' => 'whatsapp',
            ]];
        } catch (\Exception $e) {
            error_log("Error fetching parent contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Send notification via preferred channel
     */
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
            $status = 'pending';

            // Attempt delivery
            $delivered = false;
            if ($method === 'email' && !empty($parent['email'])) {
                $delivered = $this->sendEmail($parent['email'], $subject, $message);
            } elseif ($method === 'whatsapp' && !empty($parent['whatsapp_number'])) {
                $delivered = $this->sendWhatsApp($parent['whatsapp_number'], $message);
            } elseif ($method === 'sms' && !empty($parent['phone_number'])) {
                $delivered = $this->sendSMS($parent['phone_number'], $message);
            }

            $status = $delivered ? 'sent' : 'failed';

            // Log notification
            $stmt = $this->pdo->prepare("
                INSERT INTO parent_notifications_log 
                (parent_contact_id, student_id, notification_type, subject, message_body, delivery_method, delivery_status, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $parent['id'],
                $parent['student_id'] ?? null,
                $type,
                $subject,
                $message,
                $method,
                $status
            ]);

            return $delivered;
        } catch (\Exception $e) {
            error_log("Send notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email notification
     */
    private function sendEmail(string $to, string $subject, string $htmlContent): bool
    {
        try {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$this->emailFrom}\r\n";

            // In production, use proper SMTP library (SwiftMailer, PHPMailer)
            // For now, use PHP mail as fallback.
            // The @ is required: mail() raises a PHP warning when no local MTA is
            // reachable, and that warning would corrupt JSON API responses.
            return @mail($to, $subject, $htmlContent, $headers);
        } catch (\Exception $e) {
            error_log("Email send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send SMS notification via Twilio
     */
    private function sendSMS(string $phoneNumber, string $message): bool
    {
        return $this->sendSmsMessageDirect($phoneNumber, $message)['success'];
    }

    /**
     * Send WhatsApp notification via Twilio
     */
    private function sendWhatsApp(string $whatsappNumber, string $message): bool
    {
        return $this->sendWhatsAppMessageDirect($whatsappNumber, $message)['success'];
    }

    /**
     * Send WhatsApp notification via Twilio and return delivery details.
     */
    private function sendWhatsAppMessageDirect(string $whatsappNumber, string $message): array
    {
        if (!$this->twilioAccountSid || !$this->twilioAuthToken || !$this->twilioWhatsAppNumber) {
            error_log("Twilio WhatsApp: Credentials not configured");
            return [
                'success' => false,
                'error' => 'Twilio WhatsApp credentials are not configured.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL is not available on this server.',
            ];
        }

        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioAccountSid}/Messages.json";

            // Ensure phone number is in E.164 format with 'whatsapp:' prefix
            $to = 'whatsapp:' . $this->normalizePhoneNumber($whatsappNumber);
            $from = 'whatsapp:' . $this->twilioWhatsAppNumber;

            $data = [
                'From' => $from,
                'To' => $to,
                'Body' => $message
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->twilioAccountSid}:{$this->twilioAuthToken}");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $success = in_array($httpCode, [200, 201], true);

            if ($success) {
                error_log("WhatsApp sent successfully to {$to}");
            } else {
                error_log("WhatsApp send failed for {$to}: HTTP {$httpCode} - {$response}");
            }

            return [
                'success' => $success,
                'http_code' => $httpCode,
                'response' => $response,
                'error' => $curlError !== '' ? $curlError : (!$success ? 'Twilio returned HTTP ' . $httpCode . '.' : null),
                'to' => $to,
                'from' => $from,
            ];
        } catch (\Exception $e) {
            error_log("WhatsApp send error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send SMS notification via Twilio and return delivery details.
     */
    private function sendSmsMessageDirect(string $phoneNumber, string $message): array
    {
        if (!$this->twilioAccountSid || !$this->twilioAuthToken || !$this->twilioPhoneNumber) {
            error_log("Twilio SMS: Credentials not configured");
            return [
                'success' => false,
                'error' => 'Twilio SMS credentials are not configured.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL is not available on this server.',
            ];
        }

        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioAccountSid}/Messages.json";

            // Ensure phone number is in E.164 format
            $to = $this->normalizePhoneNumber($phoneNumber);

            $data = [
                'From' => $this->twilioPhoneNumber,
                'To' => $to,
                'Body' => $message
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->twilioAccountSid}:{$this->twilioAuthToken}");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $success = in_array($httpCode, [200, 201], true);

            if ($success) {
                error_log("SMS sent successfully to {$to}");
            } else {
                error_log("SMS send failed for {$to}: HTTP {$httpCode} - {$response}");
            }

            return [
                'success' => $success,
                'http_code' => $httpCode,
                'response' => $response,
                'error' => $curlError !== '' ? $curlError : (!$success ? 'Twilio returned HTTP ' . $httpCode . '.' : null),
                'to' => $to,
                'from' => $this->twilioPhoneNumber,
            ];
        } catch (\Exception $e) {
            error_log("SMS send error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize phone number to E.164 format
     * Assumes Rwanda numbers (+250) if no country code provided
     */
    private function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove all non-digit characters except leading +
        $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);
        
        // If no +, assume Rwanda (+250)
        if (strpos($cleaned, '+') === false) {
            // If starts with 0, replace with country code
            if (strpos($cleaned, '0') === 0) {
                return '+250' . substr($cleaned, 1);
            }
            // Otherwise prepend country code
            return '+250' . $cleaned;
        }
        
        return $cleaned;
    }

    /**
     * Find the first parent contact that has a value in the given number field.
     */
    private function findFirstParentContactWithChannel(string $numberField): ?array
    {
        if (!in_array($numberField, ['phone_number', 'whatsapp_number'], true)) {
            return null;
        }

        try {
            $sql = "
                SELECT pc.id, pc.student_id, pc.parent_name, pc.email, pc.phone_number, pc.whatsapp_number, pc.contact_preference,
                       s.full_name AS student_name, s.student_code, c.name AS class_name
                FROM parent_contacts pc
                INNER JOIN students s ON s.id = pc.student_id
                LEFT JOIN classes c ON c.id = s.class_id
                WHERE NULLIF(TRIM(pc.$numberField), '') IS NOT NULL
                ORDER BY pc.is_primary DESC, pc.id ASC
                LIMIT 1
            ";

            $stmt = $this->pdo->query($sql);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            return $row ?: null;
        } catch (\Exception $e) {
            error_log("Error finding first parent contact with {$numberField}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate incident notification HTML
     */
    private function generateIncidentNotification(array $student, string $reason, int $marksDeducted): string
    {
        $currentMarks = 40 - $marksDeducted;
        return "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <p>Dear Parent/Guardian,</p>
                <p>We are writing to inform you of a behavioral incident involving <strong>{$student['full_name']}</strong>.</p>
                <div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <p><strong>Incident Details:</strong></p>
                    <p><strong>Reason:</strong> {$reason}</p>
                    <p><strong>Marks Deducted:</strong> {$marksDeducted}</p>
                    <p><strong>Current Marks:</strong> {$currentMarks}/40</p>
                </div>
                <p>We encourage you to discuss this with your child and support us in fostering positive behavior.</p>
                <p>Please visit the parent portal to view more details.</p>
                <p>Best regards,<br/>School Administration</p>
            </body>
            </html>
        ";
    }

    /**
     * Generate achievement notification HTML
     */
    private function generateAchievementNotification(array $student, string $achievement): string
    {
        return "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <p>Dear Parent/Guardian,</p>
                <p>We are delighted to inform you that <strong>{$student['full_name']}</strong> has achieved:</p>
                <div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <p><strong>{$achievement}</strong></p>
                </div>
                <p>Your child's positive behavior and efforts are commendable. Keep up the great work!</p>
                <p>Best regards,<br/>School Administration</p>
            </body>
            </html>
        ";
    }

    /**
     * Generate report notification HTML
     */
    private function generateReportNotification(array $student, string $reportType): string
    {
        return "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <p>Dear Parent/Guardian,</p>
                <p>The {$reportType} for <strong>{$student['full_name']}</strong> is now available.</p>
                <p>Please log in to the parent portal to view and download the report.</p>
                <p>Best regards,<br/>School Administration</p>
            </body>
            </html>
        ";
    }

    /**
     * Get student information
     */
    private function getStudent(int $studentId): ?array
    {
        try {
            $parentContactSelect = \Database::columnExists('students', 'parent_contact') ? ', parent_contact' : '';
            $stmt = $this->pdo->prepare("
                SELECT id, full_name, student_code, class_id{$parentContactSelect}
                FROM students
                WHERE id = ?
            ");
            $stmt->execute([$studentId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            error_log("Error fetching student: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure the parent_contacts table exists on installs that missed the migration.
     */
    private function ensureParentContactsTable(): void
    {
        // CREATE TABLE forces an implicit COMMIT in MySQL, which would silently end
        // a caller's in-flight transaction, so skip the DDL when the table exists.
        if (\Database::tableExists('parent_contacts')) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS parent_contacts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_id INT UNSIGNED NOT NULL,
                parent_name VARCHAR(150) NOT NULL,
                relationship VARCHAR(30) DEFAULT NULL,
                email VARCHAR(180) DEFAULT NULL,
                phone_number VARCHAR(20) DEFAULT NULL,
                whatsapp_number VARCHAR(20) DEFAULT NULL,
                is_primary BOOLEAN DEFAULT FALSE,
                contact_preference VARCHAR(30) DEFAULT 'email',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_parent_student (student_id),
                INDEX idx_parent_primary (student_id, is_primary),
                CONSTRAINT fk_parent_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
