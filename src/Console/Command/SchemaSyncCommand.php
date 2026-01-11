<?php

declare(strict_types=1);

namespace CrisperCode\Console\Command;

use CrisperCode\Console\EntityProvider\EntityProviderInterface;
use CrisperCode\Console\EntityProvider\FrameworkEntityProvider;
use CrisperCode\Database\SchemaManager;
use MeekroDB;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to synchronize database schema from entity definitions.
 *
 * @package CrisperCode\Console\Command
 */
#[AsCommand(
    name: 'schema:sync',
    description: 'Synchronize database schema from entity definitions'
)]
class SchemaSyncCommand extends Command
{
    /** @var array<EntityProviderInterface> */
    private array $entityProviders = [];

    private LoggerInterface $logger;

    public function __construct(
        private MeekroDB $db,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
        $this->logger = $logger ?? new NullLogger();
        // Framework entities are always included by default
        $this->addEntityProvider(new FrameworkEntityProvider());
    }

    /**
     * Adds an entity provider to supply additional entities for sync.
     */
    public function addEntityProvider(EntityProviderInterface $provider): self
    {
        $this->entityProviders[] = $provider;
        return $this;
    }

    /**
     * Gets all registered entity providers.
     *
     * @return array<EntityProviderInterface>
     */
    public function getEntityProviders(): array
    {
        return $this->entityProviders;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what SQL would be executed without making changes'
            )
            ->addOption(
                'entity',
                'e',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Sync only specific entity class(es)'
            )
            ->addOption(
                'skip-framework',
                null,
                InputOption::VALUE_NONE,
                'Skip framework entities, only sync app entities'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List all registered entities without syncing'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command synchronizes database schema based on entity definitions.

Sync all entities:
  <info>php %command.full_name%</info>

Preview changes without executing:
  <info>php %command.full_name% --dry-run</info>

List all registered entities:
  <info>php %command.full_name% --list</info>

Sync specific entity:
  <info>php %command.full_name% --entity=Jot\Entity\Note</info>

Sync multiple specific entities:
  <info>php %command.full_name% -e Jot\Entity\Note -e Jot\Entity\Tag</info>

Sync only app entities (skip framework):
  <info>php %command.full_name% --skip-framework</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        /** @var array<string> $specificEntities */
        $specificEntities = $input->getOption('entity');
        $skipFramework = (bool) $input->getOption('skip-framework');
        $listOnly = (bool) $input->getOption('list');

        // Handle --list option (no database connection needed)
        if ($listOnly) {
            return $this->listEntities($io, $skipFramework);
        }

        // Verify database connection
        try {
            $this->db->query('SELECT 1');
        } catch (\Exception $e) {
            $io->error('Unable to connect to database. Check your database configuration.');
            $this->logger->error('schema:sync database connection error', [
                'exception' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }

        $manager = new SchemaManager($this->db);
        $manager->setDryRun($dryRun);
        $manager->setQuiet($output->isQuiet());

        if ($dryRun && !$output->isQuiet()) {
            $io->note('Running in DRY RUN mode - no changes will be made');
        }

        // Collect entities from all providers
        $entities = $this->collectEntities($skipFramework);

        // Filter to specific entities if requested
        if (!empty($specificEntities)) {
            $entities = $this->filterEntities($entities, $specificEntities, $io);
            if ($entities === []) {
                $io->warning('No matching entities found');
                return Command::SUCCESS;
            }
        }

        if (!$output->isQuiet()) {
            $io->section('Syncing ' . count($entities) . ' entities');
        }

        $hasErrors = false;
        foreach ($entities as $entityClass) {
            try {
                if (!$output->isQuiet()) {
                    $io->text("Syncing <info>$entityClass</info>...");
                }
                $manager->syncTable($entityClass);
            } catch (\Exception $e) {
                $io->error("Error syncing $entityClass: " . $e->getMessage());
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            return Command::FAILURE;
        }

        if (!$output->isQuiet()) {
            if ($dryRun) {
                $io->success('Dry run complete. No changes were made.');
            } else {
                $io->success('Schema synchronization complete.');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Collects entities from all registered providers.
     *
     * @return array<class-string>
     */
    private function collectEntities(bool $skipFramework): array
    {
        $entities = [];
        foreach ($this->entityProviders as $provider) {
            // Skip framework provider if requested
            if ($skipFramework && $provider instanceof FrameworkEntityProvider) {
                continue;
            }
            foreach ($provider->getEntities() as $entity) {
                // Avoid duplicates
                if (!in_array($entity, $entities, true)) {
                    $entities[] = $entity;
                }
            }
        }
        return $entities;
    }

    /**
     * Filters entities to only those matching the requested names.
     *
     * @param array<class-string> $allEntities
     * @param array<string> $requestedEntities
     * @return array<class-string>
     */
    private function filterEntities(array $allEntities, array $requestedEntities, SymfonyStyle $io): array
    {
        $filtered = [];
        foreach ($requestedEntities as $requested) {
            // Support both full class name and short name
            $found = false;
            foreach ($allEntities as $entity) {
                if ($entity === $requested || str_ends_with($entity, '\\' . $requested)) {
                    // Avoid duplicates
                    if (!in_array($entity, $filtered, true)) {
                        $filtered[] = $entity;
                    }
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $io->warning("Entity '$requested' not found in registered providers");
            }
        }
        return $filtered;
    }

    /**
     * Lists all registered entities grouped by provider.
     */
    private function listEntities(SymfonyStyle $io, bool $skipFramework): int
    {
        $io->title('Registered Entities');

        $totalCount = 0;
        foreach ($this->entityProviders as $provider) {
            if ($skipFramework && $provider instanceof FrameworkEntityProvider) {
                continue;
            }

            $providerName = (new \ReflectionClass($provider))->getShortName();
            $entities = $provider->getEntities();

            $io->section($providerName . ' (' . count($entities) . ' entities)');

            foreach ($entities as $entity) {
                $io->text('  ' . $entity);
            }

            $totalCount += count($entities);
        }

        $io->newLine();
        $io->text("<info>Total: $totalCount entities</info>");

        return Command::SUCCESS;
    }
}
