<?php

declare(strict_types=1);

namespace CrisperCode\Queue;

/**
 * Interface for job handlers that process queue jobs.
 *
 * @package CrisperCode\Queue
 */
interface JobHandlerInterface
{
    /**
     * Process the job with the given payload.
     *
     * @param array<string, mixed> $payload Job data
     * @return void
     * @throws \Exception If job processing fails
     */
    public function handle(array $payload): void;
}
