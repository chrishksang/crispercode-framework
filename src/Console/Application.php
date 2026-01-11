<?php

declare(strict_types=1);

namespace CrisperCode\Console;

use CrisperCode\Console\Command\SchemaSyncCommand;
use CrisperCode\Console\EntityProvider\EntityProviderInterface;
use MeekroDB;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Console application for framework CLI commands.
 *
 * Extends Symfony Console Application to provide a factory method
 * for building a configured console application with framework commands.
 *
 * @package CrisperCode\Console
 */
class Application extends SymfonyApplication
{
    private const string NAME = 'CrisperCode Console';
    private const string VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
    }

    /**
     * Creates a configured console application with all framework commands.
     *
     * @param MeekroDB $db Database connection
     * @param array<EntityProviderInterface> $entityProviders Additional entity providers (e.g., app entities)
     * @param LoggerInterface|null $logger Optional logger for command error logging
     * @return self Configured application instance
     */
    public static function create(
        MeekroDB $db,
        array $entityProviders = [],
        ?LoggerInterface $logger = null,
    ): self {
        $app = new self();

        // Validate entity providers at runtime
        foreach ($entityProviders as $index => $provider) {
            if (!$provider instanceof EntityProviderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Entity provider at index %d must implement EntityProviderInterface, got %s',
                    $index,
                    get_debug_type($provider)
                ));
            }
        }

        // Create and configure schema:sync command
        $schemaSyncCommand = new SchemaSyncCommand($db, $logger);
        foreach ($entityProviders as $provider) {
            $schemaSyncCommand->addEntityProvider($provider);
        }
        $app->add($schemaSyncCommand);

        return $app;
    }
}
