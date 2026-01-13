<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use CrisperCode\Queue\QueueBackendInterface;
use CrisperCode\Queue\QueueStats;
use Psr\Log\LoggerInterface;

/**
 * High-level queue service for dispatching jobs.
 *
 * @package CrisperCode\Service
 */
class QueueService
{
    public function __construct(
        private QueueBackendInterface $backend,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Dispatch a job to the queue.
     *
     * @param string $handler Fully qualified class name of job handler
     * @param array<string, mixed> $payload Job data
     * @param string $queue Queue name (default: 'default')
     * @param int $delay Delay in seconds before job becomes available
     * @param int $priority Job priority (higher = more important)
     * @return string Job ID
     */
    public function dispatch(
        string $handler,
        array $payload,
        string $queue = 'default',
        int $delay = 0,
        int $priority = 0
    ): string {
        $jobId = $this->backend->push($queue, $handler, $payload, $delay, $priority);

        $this->logger->info('Job dispatched to queue', [
            'job_id' => $jobId,
            'queue' => $queue,
            'handler' => $handler,
            'delay' => $delay,
        ]);

        return $jobId;
    }

    /**
     * Get statistics for a queue.
     */
    public function getStats(string $queue = 'default'): QueueStats
    {
        return $this->backend->getStats($queue);
    }

    /**
     * Prune old completed and failed jobs.
     */
    public function prune(int $olderThanSeconds = 86400): int
    {
        $count = $this->backend->prune($olderThanSeconds);

        $this->logger->info('Pruned old queue jobs', [
            'count' => $count,
            'older_than_seconds' => $olderThanSeconds,
        ]);

        return $count;
    }
}
