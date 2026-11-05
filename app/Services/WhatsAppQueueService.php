<?php
declare(strict_types=1);

namespace App\Services;

use Database;
use PDO;
use Throwable;

/**
 * Durable handoff between PHP and the whatsapp-web.js worker.
 *
 * PHP only ever inserts rows; the Node worker claims them, sends over WhatsApp
 * Web, and writes the outcome back. That means a saved incident is not lost
 * when the school phone is offline.
 */
class WhatsAppQueueService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function isAvailable(): bool
    {
        return Database::tableExists('notification_queue')
            && Database::tableExists('notification_logs');
    }

    /**
     * Inserts one job. Returns the new queue id, or null when the job is a
     * duplicate (same dedupe key), the phone is invalid, or the queue is
     * unavailable.
     */
    public function enqueue(
        ?int $studentId,
        string $phone,
        string $message,
        string $type = 'discipline_incident',
        ?string $dedupeKey = null
    ): ?int {
        if (!$this->isAvailable()) {
            return null;
        }

        $normalized = self::normalizePhone($phone);
        $message = trim($message);
        if ($normalized === null || $message === '') {
            return null;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO notification_queue
                    (student_id, parent_phone, message, notification_type, dedupe_key, status, next_attempt_at)
                 VALUES (:student_id, :parent_phone, :message, :notification_type, :dedupe_key, \'pending\', NOW())
                 ON DUPLICATE KEY UPDATE id = id'
            );
            $statement->execute([
                'student_id' => $studentId,
                'parent_phone' => $normalized,
                'message' => $message,
                'notification_type' => $type,
                'dedupe_key' => $dedupeKey,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            if ($id === 0) {
                return null; // duplicate dedupe_key
            }

            return $id;
        } catch (Throwable $exception) {
            Database::logError('WhatsApp queue insert failed', [
                'student_id' => $studentId,
                'exception' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Queues one message for every parent contact of the student that has
     * chosen WhatsApp and saved a WhatsApp number.
     *
     * @return array{queued:int, skipped:int, ids:array<int,int>}
     */
    public function enqueueForStudent(
        int $studentId,
        string $message,
        string $type = 'discipline_incident',
        ?string $dedupeKey = null
    ): array {
        $result = ['queued' => 0, 'skipped' => 0, 'ids' => []];

        if (!$this->isAvailable() || $studentId <= 0) {
            return $result;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT id, whatsapp_number
                   FROM parent_contacts
                  WHERE student_id = :student_id
                    AND contact_preference = \'whatsapp\'
                    AND whatsapp_number IS NOT NULL
                    AND whatsapp_number <> \'\'
                  ORDER BY is_primary DESC, id ASC'
            );
            $statement->execute(['student_id' => $studentId]);
            $contacts = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            Database::logError('WhatsApp queue contact lookup failed', [
                'student_id' => $studentId,
                'exception' => $exception->getMessage(),
            ]);
            return $result;
        }

        if ($contacts === []) {
            return $result;
        }

        foreach ($contacts as $contact) {
            $contactKey = $dedupeKey !== null
                ? $dedupeKey . ':contact-' . (int) $contact['id']
                : null;
            $id = $this->enqueue(
                $studentId,
                (string) $contact['whatsapp_number'],
                $message,
                $type,
                $contactKey
            );

            if ($id === null) {
                $result['skipped']++;
                continue;
            }

            $result['queued']++;
            $result['ids'][] = $id;
        }

        return $result;
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
        if (!$this->isAvailable()) {
            return $counts;
        }

        try {
            $rows = $this->pdo->query(
                'SELECT status, COUNT(*) AS total FROM notification_queue GROUP BY status'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
        } catch (Throwable $exception) {
            Database::logError('WhatsApp queue counts failed', ['exception' => $exception->getMessage()]);
        }

        return $counts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 40, ?string $status = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $sql = 'SELECT q.id, q.student_id, q.parent_phone, q.message, q.notification_type,
                       q.status, q.attempts, q.max_attempts, q.next_attempt_at, q.sent_at,
                       q.last_error, q.created_at,
                       s.full_name, s.student_code
                  FROM notification_queue q
                  LEFT JOIN students s ON s.id = q.student_id';
        $params = [];

        if ($status !== null && in_array($status, ['pending', 'processing', 'sent', 'failed'], true)) {
            $sql .= ' WHERE q.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY q.id DESC LIMIT ' . $limit;

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            Database::logError('WhatsApp queue list failed', ['exception' => $exception->getMessage()]);
            return [];
        }
    }

    public function retry(int $id): bool
    {
        if (!$this->isAvailable() || $id <= 0) {
            return false;
        }

        try {
            $statement = $this->pdo->prepare(
                'UPDATE notification_queue
                    SET status = \'pending\',
                        attempts = 0,
                        last_error = NULL,
                        next_attempt_at = NOW()
                  WHERE id = :id
                    AND status IN (\'failed\')'
            );
            $statement->execute(['id' => $id]);
            return $statement->rowCount() > 0;
        } catch (Throwable $exception) {
            Database::logError('WhatsApp queue retry failed', ['queue_id' => $id, 'exception' => $exception->getMessage()]);
            return false;
        }
    }

    public function cancel(int $id): bool
    {
        if (!$this->isAvailable() || $id <= 0) {
            return false;
        }

        try {
            $statement = $this->pdo->prepare(
                'UPDATE notification_queue
                    SET status = \'failed\',
                        last_error = \'Cancelled by staff\'
                  WHERE id = :id
                    AND status IN (\'pending\')'
            );
            $statement->execute(['id' => $id]);
            return $statement->rowCount() > 0;
        } catch (Throwable $exception) {
            Database::logError('WhatsApp queue cancel failed', ['queue_id' => $id, 'exception' => $exception->getMessage()]);
            return false;
        }
    }

    /**
     * Best-effort probe of the Node worker's /health endpoint.
     *
     * @return array{reachable:bool, ready:bool, workerRunning:bool, queue:array<string,int>, error:?string}
     */
    public function workerHealth(): array
    {
        $url = rtrim((string) (getenv('SBMS_WHATSAPP_URL') ?: 'http://127.0.0.1:3001'), '/') . '/health';
        $token = (string) (getenv('SBMS_WHATSAPP_HEALTH_TOKEN') ?: '');
        $result = ['reachable' => false, 'ready' => false, 'workerRunning' => false, 'queue' => [], 'error' => null];

        if (!function_exists('curl_init')) {
            $result['error'] = 'PHP cURL extension is not available.';
            return $result;
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_HTTPHEADER => $token !== '' ? ['x-health-token: ' . $token] : [],
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($body === false || $httpCode === 0) {
                $result['error'] = $curlError !== '' ? $curlError : 'Worker is not reachable.';
                return $result;
            }

            if ($httpCode === 401) {
                $result['error'] = 'Worker rejected the health token.';
                return $result;
            }

            $payload = json_decode((string) $body, true);
            if (!is_array($payload)) {
                $result['error'] = 'Worker returned an invalid health response.';
                return $result;
            }

            $result['reachable'] = true;
            $result['ready'] = !empty($payload['whatsapp']['ready']);
            $result['workerRunning'] = !empty($payload['workerRunning']);
            $result['queue'] = is_array($payload['queue'] ?? null) ? $payload['queue'] : [];
            $result['error'] = $payload['dbError'] ?? null;
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * Turns 0788123456 / +250 788 123 456 / 00250788123456 into 250788123456,
     * matching the Node worker's normaliser.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '250' . substr($digits, 1);
        }

        $length = strlen($digits);
        if ($length < 10 || $length > 15) {
            return null;
        }

        return $digits;
    }
}
