<?php

declare(strict_types=1);

namespace CrisperCode\Queue\Backend;

use CrisperCode\Entity\QueueJob;
use CrisperCode\EntityManager\QueueJobManager;
use CrisperCode\Queue\QueueBackendInterface;
use CrisperCode\Queue\QueueJobData;
use CrisperCode\Queue\QueueStats;
use MeekroDB;

/**
 * Database-backed queue implementation.
 *
 * @package CrisperCode\Queue\Backend
 */
class DatabaseQueueBackend implements QueueBackendInterface
{
    public function __construct(
        private QueueJobManager $queueJobManager,
        private MeekroDB $db
    ) {
    }

    public function push(
        string $queue,
        string $handler,
        array $payload,
        int $delay = 0,
        int $priority = 0
    ): string {
        $job = new QueueJob($this->db);
        $job->queue = $queue;
        $job->handler = $handler;

        $json = json_encode($payload);
        if ($json === false) {
            $json = '{}';
        }
        $job->payload = $json;

        $job->priority = $priority;
        $job->availableAt = date('Y-m-d H:i:s', time() + $delay);
        $job->save();

        return (string) $job->id;
    }

    public function claim(string $queue, int $timeout = 60): ?QueueJobData
    {
        $job = $this->queueJobManager->claimNextJob($queue, $timeout);

        if ($job === null) {
            return null;
        }

        return $this->toJobData($job);
    }

    public function complete(string $jobId): void
    {
        $job = $this->loadJob($jobId);
        $this->queueJobManager->markCompleted($job);
    }

    public function fail(string $jobId, string $error, int $maxAttempts = 3): void
    {
        $job = $this->loadJob($jobId);
        $job->maxAttempts = $maxAttempts;
        $this->queueJobManager->markFailed($job, $error);
    }

    public function release(string $jobId, int $delay = 0): void
    {
        $job = $this->loadJob($jobId);
        $this->queueJobManager->releaseJob($job, $delay);
    }

    public function getStats(string $queue): QueueStats
    {
        $stats = $this->queueJobManager->getStats($queue);

        return new QueueStats(
            pending: $stats['pending'],
            processing: $stats['processing'],
            completed: $stats['completed'],
            failed: $stats['failed']
        );
    }

    public function prune(int $olderThanSeconds): int
    {
        return $this->queueJobManager->pruneOldJobs($olderThanSeconds);
    }

    /**
     * Load a job by ID.
     */
    private function loadJob(string $jobId): QueueJob
    {
        $row = $this->db->queryFirstRow(
            'SELECT * FROM queue_jobs WHERE id = %i',
            (int) $jobId
        );

        if ($row === null) {
            throw new \RuntimeException("Job {$jobId} not found");
        }

        // Use the manager's entity factory to properly hydrate the entity
        $entityFactory = new \CrisperCode\EntityFactory($this->db);
        return $entityFactory->create(QueueJob::class, $row);
    }

    /**
     * Convert QueueJob entity to QueueJobData value object.
     */
    private function toJobData(QueueJob $job): QueueJobData
    {
        $payload = json_decode($job->payload, true);
        if (!is_array($payload)) {
            $payload = [
                '_raw_payload' => $job->payload,
                '_json_error' => json_last_error_msg(),
            ];
        }

        return new QueueJobData(
            id: (string) $job->id,
            queue: $job->queue,
            handler: $job->handler,
            payload: $payload,
            attempts: $job->attempts,
            maxAttempts: $job->maxAttempts
        );
    }
}
