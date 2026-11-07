<?php

declare(strict_types=1);

namespace App\Services;

use Database;
use PDO;
use Throwable;

/**
 * Durable WhatsApp queue backed by MySQL.
 * PHP enqueues; a Node whatsapp-web.js worker claims and sends.
 */
final class WhatsAppQueueService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function isAvailable(): bool
    {
        try {
            return Database::tableExists('notification_queue');
        } catch (Throwable $e) {
            return false;
        }
    }

    public function isEnabled(): bool
    {
        try {
            $db = $this->db;
            if (!Database::tableExists('settings')) {
                return true;
            }
            $stmt = $db->prepare("SELECT value FROM settings WHERE key_name = 'whatsapp_web_enabled' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null || $val === '') {
                return true;
            }
            return in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true);
        } catch (Throwable $e) {
            return true;
        }
    }

    public function enqueue(?int $studentId, string $phone, string $message, string $type = 'discipline_incident', ?string $dedupeKey = null, int $maxAttempts = 5): ?int
    {
        if (!$this->isAvailable() || !$this->isEnabled()) {
            return null;
        }

        $phone = $this->normalizePhone($phone);
        $message = trim($message);
        $type = trim($type) !== '' ? substr(trim($type), 0, 50) : 'discipline_incident';

        if ($phone === '' || $message === '') {
            return null;
        }

        $maxAttempts = max(1, min(20, $maxAttempts));

        try {
            $sql = 'INSERT INTO notification_queue
                    (student_id, parent_phone, message, notification_type, dedupe_key, status, attempts, max_attempts, next_attempt_at)
                    VALUES (:student_id, :parent_phone, :message, :notification_type, :dedupe_key, "pending", 0, :max_attempts, NOW())';
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':student_id', $studentId, $studentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':parent_phone', $phone);
            $stmt->bindValue(':message', $message);
            $stmt->bindValue(':notification_type', $type);
            $stmt->bindValue(':dedupe_key', $dedupeKey !== null && $dedupeKey !== '' ? substr($dedupeKey, 0, 120) : null, ($dedupeKey !== null && $dedupeKey !== '') ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
            $stmt->execute();

            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            // Duplicate dedupe_key - treat as already queued
            if ($dedupeKey && str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return $this->findByDedupeKey($dedupeKey);
            }
            Database::logError('WhatsApp enqueue failed', ['error' => $e->getMessage(), 'type' => $type]);
            return null;
        }
    }

    public function findByDedupeKey(string $dedupeKey): ?int
    {
        try {
            $stmt = $this->db->prepare('SELECT id FROM notification_queue WHERE dedupe_key = :k LIMIT 1');
            $stmt->execute(['k' => substr($dedupeKey, 0, 120)]);
            $id = $stmt->fetchColumn();
            return $id ? (int) $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function retry(int $queueId): bool
    {
        if ($queueId <= 0 || !$this->isAvailable()) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE notification_queue
                 SET status = "pending", next_attempt_at = NOW(), last_error = NULL
                 WHERE id = :id AND status = "failed"'
            );
            $stmt->execute(['id' => $queueId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function cancel(int $queueId): bool
    {
        if ($queueId <= 0 || !$this->isAvailable()) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE notification_queue SET status = "failed", last_error = "Cancelled by administrator"
                 WHERE id = :id AND status = "pending"'
            );
            $stmt->execute(['id' => $queueId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function retryAllFailed(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE notification_queue
                 SET status = "pending", next_attempt_at = NOW(), last_error = NULL, locked_at = NULL
                 WHERE status = "failed"'
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            try {
                $stmt = $this->db->prepare(
                    'UPDATE notification_queue SET status = "pending", next_attempt_at = NOW(), last_error = NULL WHERE status = "failed"'
                );
                $stmt->execute();
                return $stmt->rowCount();
            } catch (Throwable $e2) {
                return 0;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 60, ?string $status = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $allowed = ['pending', 'processing', 'sent', 'failed'];

        try {
            $where = '';
            $params = [];
            if ($status !== null && in_array($status, $allowed, true)) {
                $where = 'WHERE nq.status = :status';
                $params['status'] = $status;
            }

            $sql = "SELECT nq.*, s.full_name, s.student_code
                    FROM notification_queue nq
                    LEFT JOIN students s ON s.id = nq.student_id
                    {$where}
                    ORDER BY nq.id DESC
                    LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            Database::logError('WhatsApp queue recent failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @return array{pending:int,processing:int,sent:int,failed:int}
     */
    public function statusCounts(): array
    {
        $counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
        if (!$this->isAvailable()) {
            return $counts;
        }
        try {
            $rows = $this->db->query("SELECT status, COUNT(*) c FROM notification_queue GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $k = (string) ($row['status'] ?? '');
                if (array_key_exists($k, $counts)) {
                    $counts[$k] = (int) ($row['c'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $counts;
    }

    /**
     * @return array{reachable:bool,ready:bool,error:?string,data:array<string,mixed>}
     */
    public function workerHealth(): array
    {
        $url = $this->getSetting('whatsapp_worker_url', '');
        $token = $this->getSetting('whatsapp_worker_token', '');
        if ($url === '') {
            $url = (string) (getenv('WHATSAPP_WORKER_URL') ?: '');
        }
        if ($token === '') {
            $token = (string) (getenv('WHATSAPP_WORKER_TOKEN') ?: '');
        }

        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return ['reachable' => false, 'ready' => false, 'error' => 'Worker URL not configured', 'data' => []];
        }

        $healthUrl = $url . '/health';
        $ch = curl_init($healthUrl);
        if ($ch === false) {
            return ['reachable' => false, 'ready' => false, 'error' => 'cURL unavailable', 'data' => []];
        }

        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'X-Worker-Token: ' . $token;
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return ['reachable' => false, 'ready' => false, 'error' => $err !== '' ? $err : ('HTTP ' . $code), 'data' => []];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            $data = [];
        }

        $ready = !empty($data['whatsappReady']) || !empty($data['ready']) || !empty($data['ok']);
        return ['reachable' => true, 'ready' => (bool) $ready, 'error' => null, 'data' => $data];
    }

    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        $defaultCc = preg_replace('/\D+/', '', $this->getSetting('whatsapp_country_code_default', '250')) ?? '250';
        if (!$hasPlus) {
            if (str_starts_with($digits, '0')) {
                $digits = $defaultCc . substr($digits, 1);
            } elseif (!str_starts_with($digits, $defaultCc)) {
                $digits = $defaultCc . $digits;
            }
        }
        return '+' . $digits;
    }

    private function getSetting(string $key, string $default): string
    {
        try {
            if (!Database::tableExists('settings')) {
                return $default;
            }
            $stmt = $this->db->prepare('SELECT value FROM settings WHERE key_name = :k LIMIT 1');
            $stmt->execute(['k' => $key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null && $val !== '') ? (string) $val : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}
