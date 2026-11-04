<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * QueueService — database-backed job queue.
 *
 * Uses the queue_jobs table (defined in saas_platform.sql).
 * MySQL 8's SKIP LOCKED ensures multiple workers can consume the same queue
 * without contention.
 */
final class QueueService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param array       $payload      Arbitrary serialisable data
     * @param int|null    $tenantId     Optional tenant scoping
     * @param int         $delaySeconds Seconds before the job becomes available
     * @return int  Auto-increment id of the created job
     */
    public function push(string $queueName, array $payload, ?int $tenantId = null, int $delaySeconds = 0): int
    {
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));

        $stmt = $this->db->prepare(
            'INSERT INTO queue_jobs (queue_name, payload, tenant_id, status, available_at, created_at)
             VALUES (:queue_name, :payload, :tenant_id, "pending", :available_at, NOW())'
        );
        $stmt->bindValue(':queue_name',   $queueName,                             PDO::PARAM_STR);
        $stmt->bindValue(':payload',      json_encode($payload, JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id',    $tenantId,  $tenantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':available_at', $availableAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Atomically claim the next available job from the given queue.
     *
     * Returns the job row (with 'payload' already JSON-decoded) or null.
     *
     * @return array<string, mixed>|null
     */
    public function pop(string $queueName): ?array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM queue_jobs
                 WHERE  queue_name   = :queue_name
                   AND  status       = "pending"
                   AND  available_at <= NOW()
                 ORDER  BY id ASC
                 LIMIT  1
                 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute(['queue_name' => $queueName]);
            $job = $stmt->fetch();

            if (!$job) {
                $this->db->rollBack();
                return null;
            }

            $this->db->prepare(
                'UPDATE queue_jobs SET status = "processing", attempts = attempts + 1 WHERE id = :id'
            )->execute(['id' => $job['id']]);

            $this->db->commit();

            $job['payload'] = json_decode((string) $job['payload'], true) ?? [];
            return $job;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mark a job as successfully completed.
     */
    public function complete(int $jobId): void
    {
        $this->db->prepare(
            'UPDATE queue_jobs SET status = "completed", processed_at = NOW() WHERE id = :id'
        )->execute(['id' => $jobId]);
    }

    /**
     * Permanently fail a job (exceeded max attempts or unrecoverable error).
     */
    public function fail(int $jobId, string $reason): void
    {
        $this->db->prepare(
            'UPDATE queue_jobs
             SET status = "failed", failed_at = NOW(), failure_reason = :reason
             WHERE id = :id'
        )->execute(['id' => $jobId, 'reason' => substr($reason, 0, 2000)]);
    }

    /**
     * Re-queue a failed job with an exponential back-off delay.
     * Only re-queues if attempts < 3.
     */
    public function retry(int $jobId, int $delaySeconds = 60): void
    {
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        $this->db->prepare(
            'UPDATE queue_jobs
             SET status = "pending", available_at = :available_at
             WHERE id = :id AND attempts < 3'
        )->execute(['id' => $jobId, 'available_at' => $availableAt]);
    }

    /**
     * Pending job count for a queue (useful for monitoring dashboards).
     */
    public function pendingCount(string $queueName): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM queue_jobs WHERE queue_name = :q AND status = "pending"'
        );
        $stmt->execute(['q' => $queueName]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Purge completed/failed jobs older than $days days.
     */
    public function purgeOldJobs(int $days = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $stmt   = $this->db->prepare(
            'DELETE FROM queue_jobs WHERE status IN ("completed","failed") AND created_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff]);
        return $stmt->rowCount();
    }
}
