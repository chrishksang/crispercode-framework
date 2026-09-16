<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Console\Command;

use CrisperCode\Console\Command\WorkCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkCommandTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function idleSleepCases(): array
    {
        return [
            // A worker that has just drained its queue keeps the configured
            // rate for the first few empty polls, because more work usually
            // follows immediately.
            'first empty poll' => [3, 10, 1, 3],
            'still brisk at the threshold' => [3, 10, 5, 3],
            'first backoff step doubles' => [3, 10, 6, 6],
            'second step reaches the cap' => [3, 10, 7, 10],
            'stays at the cap' => [3, 10, 500, 10],
            // Left running for days: the shift is capped, so this is
            // arithmetic that terminates rather than an overflow.
            'still capped after a weekend' => [3, 10, 100000, 10],
            // A max below the base is a misconfiguration; it must not be read
            // as permission to poll faster than asked.
            'max below base never speeds up' => [5, 1, 99, 5],
            'equal base and max never backs off' => [3, 3, 99, 3],
            // --sleep=0 is a deliberate hot loop; backing it off would change
            // what the operator asked for, and 0 * 2 is still 0.
            'zero sleep stays zero' => [0, 10, 99, 0],
        ];
    }

    #[DataProvider('idleSleepCases')]
    public function testIdleSleepBacksOffAfterAnUnbrokenRunOfEmptyPolls(
        int $sleep,
        int $maxSleep,
        int $consecutiveEmptyPolls,
        int $expected
    ): void {
        $this->assertSame(
            $expected,
            WorkCommand::idleSleepSeconds($sleep, $maxSleep, $consecutiveEmptyPolls)
        );
    }

    public function testIdleSleepNeverExceedsTheCap(): void
    {
        for ($polls = 1; $polls <= 200; $polls++) {
            $this->assertLessThanOrEqual(10, WorkCommand::idleSleepSeconds(3, 10, $polls));
            $this->assertGreaterThanOrEqual(3, WorkCommand::idleSleepSeconds(3, 10, $polls));
        }
    }
}
