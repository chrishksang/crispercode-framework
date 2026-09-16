<?php

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\Database\SchemaManager;
use CrisperCode\Entity\QueueJob;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\QueueJobManager;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Claim semantics, against a real SQLite database rather than a mocked
 * connection: what is being tested is which row a second caller can still
 * reach after the first has claimed one, and a mock cannot answer that.
 */
class QueueJobManagerTest extends TestCase
{
    private MeekroDB $db;
    private QueueJobManager $manager;

    protected function setUp(): void
    {
        if (!isSQLiteAvailable()) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $this->db = createTestDatabase();

        $schemaManager = new SchemaManager($this->db);
        $schemaManager->setQuiet(true);
        $schemaManager->syncTable(QueueJob::class);

        $this->manager = new QueueJobManager($this->db, new EntityFactory($this->db));
    }

    public function testClaimReturnsNullOnAnEmptyQueue(): void
    {
        $this->assertNull($this->manager->claimNextJob('default'));
    }

    public function testClaimReservesTheJobAndCountsTheAttempt(): void
    {
        $id = $this->pushJob('default');

        $job = $this->manager->claimNextJob('default');

        $this->assertInstanceOf(QueueJob::class, $job);
        $this->assertSame($id, $job->id);
        $this->assertSame(QueueJob::STATUS_PROCESSING, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNotNull($job->reservedAt);

        // The entity the caller is handed and the row on disk agree.
        $row = $this->row($id);
        $this->assertSame(QueueJob::STATUS_PROCESSING, $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame($job->reservedAt, $row['reserved_at']);
    }

    public function testClaimTakesHighestPriorityThenOldestFirst(): void
    {
        $low = $this->pushJob('default');
        $high = $this->pushJob('default', ['priority' => 5]);
        $secondLow = $this->pushJob('default');

        $claimed = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $this->manager->claimNextJob('default');
            $this->assertNotNull($job);
            $claimed[] = $job->id;
        }

        $this->assertSame([$high, $low, $secondLow], $claimed);
        $this->assertNull($this->manager->claimNextJob('default'));
    }

    public function testClaimIgnoresOtherQueuesAndJobsNotYetAvailable(): void
    {
        $this->pushJob('other');
        $this->pushJob('default', ['available_at' => date('Y-m-d H:i:s', time() + 3600)]);

        $this->assertNull($this->manager->claimNextJob('default'));
    }

    public function testClaimDoesNotHandOutTheSameJobTwice(): void
    {
        $id = $this->pushJob('default');

        $first = $this->manager->claimNextJob('default');
        $this->assertNotNull($first);
        $this->assertSame($id, $first->id);
        $this->assertNull($this->manager->claimNextJob('default'));
    }

    /**
     * The race the conditional UPDATE exists for.
     *
     * Two workers SELECT the same pending row before either has written. The
     * pre_run hook is the interleaving: it fires when the first claimer's
     * UPDATE is about to run, and lets a second manager complete its whole
     * claim first. Exactly one of them must come away with the job, and the
     * row must record one attempt, not two.
     */
    public function testConcurrentClaimersCannotBothTakeTheSameJob(): void
    {
        $id = $this->pushJob('default');

        $rival = new QueueJobManager($this->db, new EntityFactory($this->db));
        $rivalJob = null;
        $interleaved = false;

        $this->db->addHook('pre_run', function (array $args) use (
            $rival,
            &$rivalJob,
            &$interleaved
        ): void {
            if ($interleaved || !str_starts_with(ltrim($args['query']), 'UPDATE queue_jobs')) {
                return;
            }

            // Re-entrant, so it must not arm itself again for the rival's own
            // UPDATE.
            $interleaved = true;
            $rivalJob = $rival->claimNextJob('default');
        });

        $job = $this->manager->claimNextJob('default');

        $this->assertTrue($interleaved, 'the interleaving hook never fired');
        $this->assertNotNull($rivalJob, 'the worker that got there first came away empty-handed');
        $this->assertSame($id, $rivalJob->id);
        // The loser is told the queue is empty rather than handed a job that
        // is already someone else's.
        $this->assertNull($job);

        $row = $this->row($id);
        $this->assertSame(QueueJob::STATUS_PROCESSING, $row['status']);
        $this->assertSame(1, (int) $row['attempts'], 'the job was reserved twice');
    }

    /**
     * Same race, on the reclaim path: a timed-out reservation is taken over by
     * one caller only.
     */
    public function testConcurrentReclaimersCannotBothTakeTheSameStaleJob(): void
    {
        $id = $this->pushJob('default', [
            'status' => QueueJob::STATUS_PROCESSING,
            'reserved_at' => date('Y-m-d H:i:s', time() - 600),
            'attempts' => 1,
        ]);

        $rival = new QueueJobManager($this->db, new EntityFactory($this->db));
        $rivalJob = null;
        $interleaved = false;

        $this->db->addHook('pre_run', function (array $args) use (
            $rival,
            &$rivalJob,
            &$interleaved
        ): void {
            if ($interleaved || !str_starts_with(ltrim($args['query']), 'UPDATE queue_jobs')) {
                return;
            }

            $interleaved = true;
            $rivalJob = $rival->claimNextJob('default', 60);
        });

        $job = $this->manager->claimNextJob('default', 60);

        $this->assertTrue($interleaved, 'the interleaving hook never fired');
        $this->assertNotNull($rivalJob);
        $this->assertSame($id, $rivalJob->id);
        $this->assertNull($job);
        $this->assertSame(2, (int) $this->row($id)['attempts'], 'the job was reclaimed twice');
    }

    public function testClaimReclaimsAReservationPastItsTimeout(): void
    {
        $id = $this->pushJob('default', [
            'status' => QueueJob::STATUS_PROCESSING,
            'reserved_at' => date('Y-m-d H:i:s', time() - 120),
            'attempts' => 1,
        ]);

        $job = $this->manager->claimNextJob('default', 60);

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame(QueueJob::STATUS_PROCESSING, $job->status);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(2, (int) $this->row($id)['attempts']);
    }

    public function testClaimLeavesAReservationInsideItsTimeoutAlone(): void
    {
        $this->pushJob('default', [
            'status' => QueueJob::STATUS_PROCESSING,
            'reserved_at' => date('Y-m-d H:i:s', time() - 5),
            'attempts' => 1,
        ]);

        $this->assertNull($this->manager->claimNextJob('default', 60));
    }

    public function testPendingWorkIsPreferredToReclaimableWork(): void
    {
        $this->pushJob('default', [
            'status' => QueueJob::STATUS_PROCESSING,
            'reserved_at' => date('Y-m-d H:i:s', time() - 600),
            'attempts' => 1,
        ]);
        $pending = $this->pushJob('default');

        $job = $this->manager->claimNextJob('default', 60);
        $this->assertNotNull($job);
        $this->assertSame($pending, $job->id);
    }

    /**
     * The reason this file exists at all: an idle worker polling a queue must
     * not open a write transaction on a database every other container is
     * writing to. Asserted against a mock because "did not start a
     * transaction" is a statement about the calls, not about the rows.
     */
    public function testEmptyPollOpensNoTransaction(): void
    {
        /** @var MeekroDB&MockObject $db */
        $db = $this->createMock(MeekroDB::class);
        $db->expects($this->never())->method('startTransaction');
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db->method('queryFirstRow')->willReturn(null);
        // Two SELECTs - pending, then reclaimable - and no write.
        $db->expects($this->never())->method('query');

        $manager = new QueueJobManager($db, new EntityFactory($this->db));

        $this->assertNull($manager->claimNextJob('default'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function pushJob(string $queue, array $overrides = []): int
    {
        $this->db->insert('queue_jobs', array_merge([
            'queue' => $queue,
            'handler' => 'Tests\\CrisperCode\\NullHandler',
            'payload' => '{}',
            'status' => QueueJob::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 3,
            'priority' => 0,
            'available_at' => date('Y-m-d H:i:s', time() - 1),
            'created_at' => date('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->db->insertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->db->queryFirstRow('SELECT * FROM queue_jobs WHERE id = %i', $id);
        $this->assertNotNull($row);

        return $row;
    }
}
