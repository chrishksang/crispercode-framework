<?php

declare(strict_types=1);

namespace CrisperCode\Queue;

/**
 * Interface for queue backend implementations.
 *
 * Allows swapping between database, Redis, SQS, or other queue backends.
 *
 * @package CrisperCode\Queue
 */
interface QueueBackendInterface
{
    /**
     * Push a new job to the queue.
     *
     * @param string $queue Queue name
     * @param string $handler Fully qualified class name of job handler
     * @param array<string, mixed> $payload Job data
     * @param int $delay Delay in seconds before job becomes available
     * @param int $priority Job priority (higher = more important)
     * @return string Job ID
     */
    public function push(
        string $queue,
        string $handler,
        array $payload,
        int $delay = 0,
        int $priority = 0
    ): string;

    /**
     * Claim the next available job from the queue (atomic operation).
     *
     * @param string $queue Queue name
     * @param int $timeout Reservation timeout in seconds
     * @return QueueJobData|null Job data if available, null if queue is empty
     */
    public function claim(string $queue, int $timeout = 60): ?QueueJobData;

    /**
     * Mark a job as successfully completed.
     *
     * @param string $jobId Job identifier
     * @return void
     */
    public function complete(string $jobId): void;

    /**
     * Mark a job as failed.
     *
     * If the job has not exceeded max attempts, it will be retried.
     * Otherwise, it will be marked as permanently failed.
     *
     * @param string $jobId Job identifier
     * @param string $error Error message
     * @param int $maxAttempts Maximum retry attempts
     * @return void
     */
    public function fail(string $jobId, string $error, int $maxAttempts = 3): void;

    /**
     * Release a job back to the queue for retry.
     *
     * @param string $jobId Job identifier
     * @param int $delay Delay in seconds before retry
     * @return void
     */
    public function release(string $jobId, int $delay = 0): void;

    /**
     * Get statistics for a specific queue.
     *
     * @param string $queue Queue name
     * @return QueueStats Queue statistics
     */
    public function getStats(string $queue): QueueStats;

    /**
     * Prune old completed and failed jobs.
     *
     * @param int $olderThanSeconds Only prune jobs older than this
     * @return int Number of jobs pruned
     */
    public function prune(int $olderThanSeconds): int;
}
