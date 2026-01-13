<?php

declare(strict_types=1);

namespace CrisperCode\Queue;

/**
 * Value object representing a queue job (backend-agnostic).
 *
 * @package CrisperCode\Queue
 */
class QueueJobData
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $handler,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $maxAttempts
    ) {
    }
}
