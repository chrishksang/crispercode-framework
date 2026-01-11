<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Console\Command;

use CrisperCode\Console\Command\SchemaSyncCommand;
use CrisperCode\Console\EntityProvider\EntityProviderInterface;
use CrisperCode\Console\EntityProvider\FrameworkEntityProvider;
use CrisperCode\Entity\User;
use MeekroDB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SchemaSyncCommandTest extends TestCase
{
    private MeekroDB $dbMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
    }

    public function testCommandHasCorrectName(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);

        $this->assertEquals('schema:sync', $command->getName());
    }

    public function testCommandHasCorrectDescription(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);

        $this->assertEquals(
            'Synchronize database schema from entity definitions',
            $command->getDescription()
        );
    }

    public function testCommandHasDryRunOption(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertFalse($definition->getOption('dry-run')->acceptValue());
    }

    public function testCommandHasEntityOption(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('entity'));
        $this->assertEquals('e', $definition->getOption('entity')->getShortcut());
        $this->assertTrue($definition->getOption('entity')->isArray());
    }

    public function testCommandHasSkipFrameworkOption(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('skip-framework'));
        $this->assertFalse($definition->getOption('skip-framework')->acceptValue());
    }

    public function testAddEntityProviderReturnsFluentInterface(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $provider = $this->createMock(EntityProviderInterface::class);

        $result = $command->addEntityProvider($provider);

        $this->assertSame($command, $result);
    }

    public function testGetEntityProvidersIncludesFrameworkByDefault(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $providers = $command->getEntityProviders();

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(FrameworkEntityProvider::class, $providers[0]);
    }

    public function testAddEntityProviderAddsToList(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $customProvider = $this->createMock(EntityProviderInterface::class);

        $command->addEntityProvider($customProvider);
        $providers = $command->getEntityProviders();

        $this->assertCount(2, $providers);
        $this->assertInstanceOf(FrameworkEntityProvider::class, $providers[0]);
        $this->assertSame($customProvider, $providers[1]);
    }

    public function testExecuteReturnsFailureOnDatabaseConnectionError(): void
    {
        $this->dbMock->method('query')
            ->willThrowException(new \Exception('Connection refused'));

        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Unable to connect to database', $tester->getDisplay());
    }

    public function testDryRunModeShowsNote(): void
    {
        // Mock successful connection but we'll let it fail on sync
        // since we can't easily mock SchemaManager internals
        $this->dbMock->method('query')
            ->willReturnCallback(function ($sql) {
                if ($sql === 'SELECT 1') {
                    return true;
                }
                throw new \Exception('Not implemented');
            });

        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        // This will fail during sync, but we can check the dry-run note appeared
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN mode', $tester->getDisplay());
    }

    public function testQuietModeSuppressesOutput(): void
    {
        $this->dbMock->method('query')
            ->willThrowException(new \Exception('Connection refused'));

        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([], ['verbosity' => 16]); // 16 = quiet

        // In quiet mode, output is suppressed but exit code reflects failure
        $this->assertEquals(1, $exitCode);
        // Output should be empty or minimal in quiet mode
        $display = $tester->getDisplay();
        $this->assertEmpty($display);
    }

    public function testFilterEntitiesByShortName(): void
    {
        // Create a mock provider that returns User
        $provider = $this->createMock(EntityProviderInterface::class);
        $provider->method('getEntities')->willReturn([User::class]);

        $command = new SchemaSyncCommand($this->dbMock);
        // Clear default providers by creating fresh command and only add our mock
        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('entityProviders');
        $property->setValue($command, [$provider]);

        // Mock DB to pass connection check but fail on actual sync
        $this->dbMock->method('query')
            ->willReturnCallback(function ($sql) {
                if ($sql === 'SELECT 1') {
                    return true;
                }
                throw new \Exception('Not implemented');
            });

        $tester = new CommandTester($command);
        $tester->execute(['--entity' => ['User'], '--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('User', $display);
    }

    public function testFilterEntitiesWarnsOnUnknown(): void
    {
        $this->dbMock->method('query')->willReturn(true);

        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        $tester->execute(['--entity' => ['NonExistentEntity'], '--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString("'NonExistentEntity' not found", $display);
    }

    public function testCommandHasListOption(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('list'));
        $this->assertEquals('l', $definition->getOption('list')->getShortcut());
        $this->assertFalse($definition->getOption('list')->acceptValue());
    }

    public function testListOptionShowsRegisteredEntities(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--list' => true]);

        $this->assertEquals(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Registered Entities', $display);
        $this->assertStringContainsString('FrameworkEntityProvider', $display);
        $this->assertStringContainsString('User', $display);
        $this->assertStringContainsString('Total:', $display);
    }

    public function testListOptionDoesNotRequireDatabaseConnection(): void
    {
        // Database will throw if queried - list should not need it
        $this->dbMock->method('query')
            ->willThrowException(new \Exception('Should not be called'));

        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--list' => true]);

        // Should succeed without hitting the database
        $this->assertEquals(0, $exitCode);
    }

    public function testListOptionWithSkipFramework(): void
    {
        $customProvider = $this->createMock(EntityProviderInterface::class);
        $customProvider->method('getEntities')->willReturn(['App\\Entity\\TestEntity']);

        $command = new SchemaSyncCommand($this->dbMock);
        $command->addEntityProvider($customProvider);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--list' => true, '--skip-framework' => true]);

        $this->assertEquals(0, $exitCode);
        $display = $tester->getDisplay();
        // Should not contain framework provider
        $this->assertStringNotContainsString('FrameworkEntityProvider', $display);
        // Should contain our custom entity
        $this->assertStringContainsString('TestEntity', $display);
    }

    public function testListOptionShowsEntityCount(): void
    {
        $command = new SchemaSyncCommand($this->dbMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--list' => true]);

        $this->assertEquals(0, $exitCode);
        $display = $tester->getDisplay();
        // FrameworkEntityProvider has 5 entities
        $this->assertStringContainsString('5 entities', $display);
        $this->assertStringContainsString('Total: 5', $display);
    }
}
