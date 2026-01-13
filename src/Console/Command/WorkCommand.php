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
            ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Job reservation timeout', 60);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = $input->getArgument('queue');
        $once = $input->getOption('once');
        $sleep = (int) $input->getOption('sleep');
        $timeout = (int) $input->getOption('timeout');

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

                if ($output->isVerbose() || time() - $this->lastNoJobsMessageAt >= 30) {
                    $output->writeln("<comment>No jobs available. Sleeping {$sleep}s...</comment>");
                    $this->lastNoJobsMessageAt = time();
                }

                sleep($sleep);
                continue;
            }

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
     * Signal handler for graceful shutdown.
     */
    public function signalHandler(int $signal): void
    {
        $this->logger->info('Received shutdown signal', ['signal' => $signal]);
        $this->shouldQuit = true;
    }
}
