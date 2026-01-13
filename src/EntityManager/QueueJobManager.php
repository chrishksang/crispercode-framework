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
     * Atomically claim the next available job from a queue.
     *
     * @param string $queue Queue name
     * @param int $timeout Reservation timeout in seconds
     * @return QueueJob|null Claimed job or null if queue is empty
     */
    public function claimNextJob(string $queue, int $timeout = 60): ?QueueJob
    {
        $this->db->startTransaction();

        try {
            // Find next available job with locking
            $now = date('Y-m-d H:i:s');
            $row = $this->db->queryFirstRow(
                "SELECT * FROM queue_jobs 
                WHERE queue = %s 
                AND status = %s 
                AND available_at <= %s
                ORDER BY priority DESC, id ASC
                LIMIT 1
                FOR UPDATE",
                $queue,
                QueueJob::STATUS_PENDING,
                $now
            );

            if ($row === null) {
                $this->db->commit();
                return null;
            }

            // Mark as processing
            /** @var QueueJob $job */
            $job = $this->entityFactory->create(QueueJob::class, $row);
            $job->status = QueueJob::STATUS_PROCESSING;
            $job->reservedAt = $now;
            $job->attempts++;
            $job->save();

            $this->db->commit();
            return $job;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
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
