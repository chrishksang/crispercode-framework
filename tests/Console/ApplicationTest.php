<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Console;

use CrisperCode\Console\Application;
use CrisperCode\Console\Command\SchemaSyncCommand;
use CrisperCode\Console\EntityProvider\EntityProviderInterface;
use MeekroDB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as SymfonyApplication;

class ApplicationTest extends TestCase
{
    private MeekroDB $dbMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
    }

    public function testExtendsSymfonyApplication(): void
    {
        $app = new Application();

        $this->assertInstanceOf(SymfonyApplication::class, $app);
    }

    public function testHasCorrectName(): void
    {
        $app = new Application();

        $this->assertEquals('CrisperCode Console', $app->getName());
    }

    public function testHasVersion(): void
    {
        $app = new Application();

        $this->assertEquals('1.0.0', $app->getVersion());
    }

    public function testCreateReturnsApplicationInstance(): void
    {
        $app = Application::create($this->dbMock);

        $this->assertInstanceOf(Application::class, $app);
    }

    public function testCreateRegistersSchemaSyncCommand(): void
    {
        $app = Application::create($this->dbMock);

        $this->assertTrue($app->has('schema:sync'));
        $this->assertInstanceOf(SchemaSyncCommand::class, $app->find('schema:sync'));
    }

    public function testCreateWithEntityProvidersRegistersThemWithCommand(): void
    {
        $mockProvider = $this->createMock(EntityProviderInterface::class);
        $mockProvider->method('getEntities')->willReturn(['App\\Entity\\TestEntity']);

        $app = Application::create($this->dbMock, [$mockProvider]);

        /** @var SchemaSyncCommand $command */
        $command = $app->find('schema:sync');
        $providers = $command->getEntityProviders();

        // Should have FrameworkEntityProvider (default) + our mock
        $this->assertCount(2, $providers);
        $this->assertSame($mockProvider, $providers[1]);
    }

    public function testCreateWithMultipleEntityProviders(): void
    {
        $provider1 = $this->createMock(EntityProviderInterface::class);
        $provider1->method('getEntities')->willReturn(['App\\Entity\\Entity1']);

        $provider2 = $this->createMock(EntityProviderInterface::class);
        $provider2->method('getEntities')->willReturn(['App\\Entity\\Entity2']);

        $app = Application::create($this->dbMock, [$provider1, $provider2]);

        /** @var SchemaSyncCommand $command */
        $command = $app->find('schema:sync');
        $providers = $command->getEntityProviders();

        // FrameworkEntityProvider + 2 custom providers
        $this->assertCount(3, $providers);
    }

    public function testCreateWithEmptyProvidersArrayUsesOnlyFramework(): void
    {
        $app = Application::create($this->dbMock, []);

        /** @var SchemaSyncCommand $command */
        $command = $app->find('schema:sync');
        $providers = $command->getEntityProviders();

        // Only FrameworkEntityProvider
        $this->assertCount(1, $providers);
    }

    public function testCreateThrowsExceptionForInvalidProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement EntityProviderInterface');

        // @phpstan-ignore argument.type
        Application::create($this->dbMock, ['not a provider']);
    }

    public function testCreateThrowsExceptionForInvalidProviderAtSpecificIndex(): void
    {
        $validProvider = $this->createMock(EntityProviderInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('index 1');

        // @phpstan-ignore argument.type
        Application::create($this->dbMock, [$validProvider, new \stdClass()]);
    }
}
