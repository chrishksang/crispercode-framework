<?php

declare(strict_types=1);

namespace CrisperCode\Console\Command;

use CrisperCode\Queue\JobHandlerInterface;
use CrisperCode\Queue\QueueBackendInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to process queue jobs.
 *
 * @package CrisperCode\Console\Command
 */
#[AsCommand(
    name: 'queue:work',
    description: 'Process jobs from the queue'
)]
class WorkCommand extends Command
{
    /**
     * Empty polls tolerated at the full poll rate before backing off.
     *
     * Low enough that a worker that has just drained its queue keeps checking
     * briskly for the work that usually follows, high enough that a burst
     * arriving mid-backoff is not what sets the pace.
     */
    private const EMPTY_POLLS_BEFORE_BACKOFF = 5;

    private bool $shouldQuit = false;
    private int $lastNoJobsMessageAt = 0;

    public function __construct(
        private QueueBackendInterface $backend,
        private LoggerInterface $logger,
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('queue', InputArgument::OPTIONAL, 'Queue to process', 'default')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one job then exit')
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Seconds to sleep when no jobs available', 3)
            ->addOption(
                'max-sleep',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds to sleep once a queue has been idle for a while',
                10
            )
            ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Job reservation timeout', 60);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = $input->getArgument('queue');
        $once = $input->getOption('once');
        $sleep = (int) $input->getOption('sleep');
        $maxSleep = (int) $input->getOption('max-sleep');
        $timeout = (int) $input->getOption('timeout');
        $consecutiveEmptyPolls = 0;

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'signalHandler']);
            pcntl_signal(SIGINT, [$this, 'signalHandler']);
        }

        $output->writeln("<info>Starting worker for queue: {$queue}</info>");

        while (!$this->shouldQuit) {
            // Dispatch signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $jobData = $this->backend->claim($queue, $timeout);

            if ($jobData === null) {
                if ($once) {
                    $output->writeln('<comment>No jobs available. Exiting.</comment>');
                    return Command::SUCCESS;
                }

                $consecutiveEmptyPolls++;
                $idleSleep = self::idleSleepSeconds($sleep, $maxSleep, $consecutiveEmptyPolls);

                if ($output->isVerbose() || time() - $this->lastNoJobsMessageAt >= 30) {
                    $output->writeln("<comment>No jobs available. Sleeping {$idleSleep}s...</comment>");
                    $this->lastNoJobsMessageAt = time();
                }

                sleep($idleSleep);
                continue;
            }

            $consecutiveEmptyPolls = 0;

            $output->writeln("<info>Processing job {$jobData->id} ({$jobData->handler})</info>");

            try {
                // Instantiate handler - try container first for dependency injection
                if (!class_exists($jobData->handler)) {
                    throw new \RuntimeException("Handler class {$jobData->handler} not found");
                }

                // Try to get handler from container (for DI), otherwise instantiate directly
                if ($this->container->has($jobData->handler)) {
                    $handler = $this->container->get($jobData->handler);
                } else {
                    $ref = new \ReflectionClass($jobData->handler);
                    $ctor = $ref->getConstructor();
                    if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                        throw new \RuntimeException(
                            "Handler {$jobData->handler} has required constructor arguments; register it in the container."
                        );
                    }

                    $handler = $ref->newInstance();
                }

                if (!$handler instanceof JobHandlerInterface) {
                    throw new \RuntimeException(
                        "Handler {$jobData->handler} must implement JobHandlerInterface"
                    );
                }

                // Execute job
                $handler->handle($jobData->payload);

                // Mark as completed
                $this->backend->complete($jobData->id);

                $output->writeln("<info>✓ Job {$jobData->id} completed</info>");
                $this->logger->info('Job completed', [
                    'job_id' => $jobData->id,
                    'handler' => $jobData->handler,
                ]);
            } catch (\Exception $e) {
                // Mark as failed
                $this->backend->fail($jobData->id, $e->getMessage(), $jobData->maxAttempts);

                $output->writeln("<error>✗ Job {$jobData->id} failed: {$e->getMessage()}</error>");
                $this->logger->error('Job failed', [
                    'job_id' => $jobData->id,
                    'handler' => $jobData->handler,
                    'error' => $e->getMessage(),
                    'attempts' => $jobData->attempts,
                ]);
            }

            if ($once) {
                return Command::SUCCESS;
            }
        }

        $output->writeln('<comment>Worker shutting down...</comment>');
        return Command::SUCCESS;
    }

    /**
     * How long to sleep after an unbroken run of empty polls.
     *
     * A poll is cheap but not free - it is two indexed SELECTs against a file
     * every other container is also writing to - and a queue that has been
     * quiet for twenty seconds is usually quiet for minutes. So the first few
     * empty polls keep the configured rate and the rest double up to
     * $maxSleep, which a single claimed job resets.
     *
     * Public and static because the arithmetic is the part worth testing, and
     * testing it through the loop would mean actually sleeping.
     */
    public static function idleSleepSeconds(int $sleep, int $maxSleep, int $consecutiveEmptyPolls): int
    {
        // A max below the base is a misconfiguration, not an instruction to
        // poll faster than asked.
        $maxSleep = max($sleep, $maxSleep);

        $steps = $consecutiveEmptyPolls - self::EMPTY_POLLS_BEFORE_BACKOFF;
        if ($steps <= 0) {
            return $sleep;
        }

        // Capped before the shift so a worker left running for days cannot
        // overflow it.
        return (int) min($maxSleep, $sleep * 2 ** min($steps, 16));
    }

    /**
     * Signal handler for graceful shutdown.
     */
    public function signalHandler(int $signal): void
    {
        $this->logger->info('Received shutdown signal', ['signal' => $signal]);
        $this->shouldQuit = true;
    }
}
