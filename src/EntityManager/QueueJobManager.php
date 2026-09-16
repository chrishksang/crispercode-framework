<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\QueueJob;
use CrisperCode\EntityFactory;
use MeekroDB;

/**
 * Entity manager for queue jobs.
 *
 * @package CrisperCode\EntityManager
 */
#[EntityManagerAttribute(entityClass: QueueJob::class)]
class QueueJobManager extends EntityManagerBase
{
    public function __construct(
        MeekroDB $db,
        EntityFactory $entityFactory
    ) {
        parent::__construct($db, $entityFactory);
    }

    /**
     * Number of times a lost claim race is retried before the caller is told
     * there is nothing pending. A loser only ever loses to a winner, so one
     * more look is nearly always enough; the bound is there so a pathological
     * pile-up of workers cannot spin here instead of returning to the poll
     * loop.
     */
    private const CLAIM_ATTEMPTS = 3;

    /**
     * Atomically claim the next available job from a queue.
     *
     * The claim is the conditional UPDATE below, and nothing else: exactly one
     * caller can move a row out of `pending`, and affectedRows() tells that
     * caller whether it was the one who did. Losing the race is normal and
     * costs another look, not a job.
     *
     * Deliberately transaction-free. Wrapping the poll in one made every
     * empty poll open a write transaction, and in WAL mode writers do not
     * block readers but do block writers: three idle workers on a shared
     * sqlite file were taking a write lock roughly once a second between
     * them, against the same file the web container commits sessions,
     * remember-token refreshes and queue pushes to.
     *
     * @param string $queue Queue name
     * @param int $timeout Reservation timeout in seconds
     * @return QueueJob|null Claimed job or null if queue is empty
     */
    public function claimNextJob(string $queue, int $timeout = 60): ?QueueJob
    {
        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $now = date('Y-m-d H:i:s');

            $row = $this->db->queryFirstRow(
                "SELECT * FROM queue_jobs
                WHERE queue = %s
                AND status = %s
                AND available_at <= %s
                ORDER BY priority DESC, id ASC
                LIMIT 1",
                $queue,
                QueueJob::STATUS_PENDING,
                $now
            );

            // Nothing pending: an empty poll is now two SELECTs and no write
            // at all.
            if ($row === null) {
                break;
            }

            $job = $this->reservePending($row, $now);
            if ($job !== null) {
                return $job;
            }
        }

        return $this->reclaimTimedOutJob($queue, $timeout);
    }

    /**
     * Move a pending row to processing, or report that someone else did.
     *
     * @param array<string, mixed> $row The row as SELECTed.
     * @return QueueJob|null The claimed job, or null if the race was lost.
     */
    private function reservePending(array $row, string $now): ?QueueJob
    {
        $this->db->query(
            "UPDATE queue_jobs
            SET status = %s, reserved_at = %s, attempts = attempts + 1
            WHERE id = %i AND status = %s",
            QueueJob::STATUS_PROCESSING,
            $now,
            (int) $row['id'],
            QueueJob::STATUS_PENDING
        );

        // `status = pending` in the WHERE clause is the whole claim: a second
        // worker that SELECTed the same row matches nothing and gets 0 here.
        if ($this->db->affectedRows() !== 1) {
            return null;
        }

        return $this->hydrateReserved($row, $now);
    }

    /**
     * Take over a reservation whose owner has gone past the timeout.
     *
     * Reached only when nothing is pending, so a busy queue never pays for it.
     */
    private function reclaimTimedOutJob(string $queue, int $timeout): ?QueueJob
    {
        $timeoutCutoff = date('Y-m-d H:i:s', time() - $timeout);

        $row = $this->db->queryFirstRow(
            "SELECT * FROM queue_jobs
            WHERE queue = %s
            AND status = %s
            AND reserved_at IS NOT NULL
            AND reserved_at <= %s
            ORDER BY reserved_at ASC, priority DESC, id ASC
            LIMIT 1",
            $queue,
            QueueJob::STATUS_PROCESSING,
            $timeoutCutoff
        );

        if ($row === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');

        // The staleness test is repeated in the WHERE clause rather than
        // trusting the SELECT: what makes this row claimable is its old
        // reserved_at, so a row re-reserved in between - by its original
        // owner finishing and the job being retried, or by another reclaimer -
        // must stay with whoever holds it now.
        $this->db->query(
            "UPDATE queue_jobs
            SET reserved_at = %s, attempts = attempts + 1
            WHERE id = %i
            AND status = %s
            AND reserved_at IS NOT NULL
            AND reserved_at <= %s",
            $now,
            (int) $row['id'],
            QueueJob::STATUS_PROCESSING,
            $timeoutCutoff
        );

        if ($this->db->affectedRows() !== 1) {
            return null;
        }

        return $this->hydrateReserved($row, $now);
    }

    /**
     * Build the entity for a row this process has just reserved.
     *
     * From the row plus exactly what the UPDATE did, rather than re-reading
     * it: the reservation is ours, so nothing else is going to change the row,
     * and hydrating from the row as SELECTed would hand the caller a job whose
     * status and attempt count the database disagrees with.
     *
     * @param array<string, mixed> $row The row as SELECTed.
     */
    private function hydrateReserved(array $row, string $now): QueueJob
    {
        $row['status'] = QueueJob::STATUS_PROCESSING;
        $row['reserved_at'] = $now;
        $row['attempts'] = (int) $row['attempts'] + 1;

        /** @var QueueJob $job */
        $job = $this->entityFactory->create(QueueJob::class, $row);

        return $job;
    }

    /**
     * Mark a job as successfully completed.
     */
    public function markCompleted(QueueJob $job): void
    {
        $job->status = QueueJob::STATUS_COMPLETED;
        $job->completedAt = date('Y-m-d H:i:s');
        $job->save();
    }

    /**
     * Mark a job as failed.
     *
     * If max attempts not exceeded, returns to pending for retry.
     * Otherwise marks as permanently failed.
     */
    public function markFailed(QueueJob $job, string $error): void
    {
        $job->error = $error;

        if ($job->attempts >= $job->maxAttempts) {
            // Permanently failed
            $job->status = QueueJob::STATUS_FAILED;
            $job->failedAt = date('Y-m-d H:i:s');
        } else {
            // Retry - return to pending
            $job->status = QueueJob::STATUS_PENDING;
            $job->reservedAt = null;
            // Exponential backoff: 2^attempts minutes
            $delay = pow(2, $job->attempts) * 60;
            $job->availableAt = date('Y-m-d H:i:s', time() + $delay);
        }

        $job->save();
    }

    /**
     * Release a job back to the queue for retry.
     */
    public function releaseJob(QueueJob $job, int $delay = 0): void
    {
        $job->status = QueueJob::STATUS_PENDING;
        $job->reservedAt = null;
        $job->availableAt = date('Y-m-d H:i:s', time() + $delay);
        $job->save();
    }

    /**
     * Get statistics for a specific queue.
     */
    public function getStats(string $queue): array
    {
        $stats = $this->db->queryFirstRow(
            "SELECT 
                SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as failed
            FROM queue_jobs
            WHERE queue = %s",
            QueueJob::STATUS_PENDING,
            QueueJob::STATUS_PROCESSING,
            QueueJob::STATUS_COMPLETED,
            QueueJob::STATUS_FAILED,
            $queue
        );

        return [
            'pending' => (int) $stats['pending'],
            'processing' => (int) $stats['processing'],
            'completed' => (int) $stats['completed'],
            'failed' => (int) $stats['failed'],
        ];
    }

    /**
     * Prune old completed and failed jobs.
     *
     * @param int $olderThanSeconds Only prune jobs older than this
     * @return int Number of jobs deleted
     */
    public function pruneOldJobs(int $olderThanSeconds): int
    {
        $cutoffDate = date('Y-m-d H:i:s', time() - $olderThanSeconds);

        $this->db->delete(
            'queue_jobs',
            'status = %s AND completed_at < %s',
            QueueJob::STATUS_COMPLETED,
            $cutoffDate
        );

        $completedDeleted = $this->db->affectedRows();

        $this->db->delete(
            'queue_jobs',
            'status = %s AND failed_at < %s',
            QueueJob::STATUS_FAILED,
            $cutoffDate
        );

        $failedDeleted = $this->db->affectedRows();

        return $completedDeleted + $failedDeleted;
    }
}
