<?php

declare(strict_types=1);

namespace CrisperCode\Queue;

/**
 * Value object representing queue statistics.
 *
 * @package CrisperCode\Queue
 */
class QueueStats
{
    public function __construct(
        public readonly int $pending,
        public readonly int $processing,
        public readonly int $completed,
        public readonly int $failed
    ) {
    }

    /**
     * Get total number of jobs across all states.
     */
    public function total(): int
    {
        return $this->pending + $this->processing + $this->completed + $this->failed;
    }
}
