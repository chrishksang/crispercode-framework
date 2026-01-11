<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service;

use CrisperCode\EntityManager\KeyValueManager;
use CrisperCode\Service\KeyValueService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class KeyValueServiceTest extends TestCase
{
    protected KeyValueManager&MockObject $manager;
    protected KeyValueService $service;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(KeyValueManager::class);
        $this->service = new KeyValueService($this->manager);
    }

    public function testGetConfigDelegatesToManager(): void
    {
        $key = 'app.name';
        $default = 'Default App';
        $expectedValue = 'My Application';

        $this->manager->expects($this->once())
            ->method('get')
            ->with("config.{$key}", $default)
            ->willReturn($expectedValue);

        $result = $this->service->getConfig($key, $default);
        $this->assertEquals($expectedValue, $result);
    }

    public function testSetConfigDelegatesToManager(): void
    {
        $key = 'app.name';
        $value = 'My Application';

        $this->manager->expects($this->once())
            ->method('set')
            ->with("config.{$key}", $value);

        $this->service->setConfig($key, $value);
    }

    public function testGetMetadataDelegatesToManager(): void
    {
        $key = 'last.sync';
        $default = null;
        $expectedValue = '2024-01-01 00:00:00';

        $this->manager->expects($this->once())
            ->method('get')
            ->with("metadata.{$key}", $default)
            ->willReturn($expectedValue);

        $result = $this->service->getMetadata($key, $default);
        $this->assertEquals($expectedValue, $result);
    }

    public function testSetMetadataDelegatesToManager(): void
    {
        $key = 'last.sync';
        $value = '2024-01-01 00:00:00';

        $this->manager->expects($this->once())
            ->method('set')
            ->with("metadata.{$key}", $value);

        $this->service->setMetadata($key, $value);
    }

    public function testDeleteDelegatesToManager(): void
    {
        $key = 'test.key';

        $this->manager->expects($this->once())
            ->method('deleteByKey')
            ->with($key);

        $this->service->delete($key);
    }

    public function testExistsDelegatesToManager(): void
    {
        $key = 'test.key';

        $this->manager->expects($this->once())
            ->method('existsByKey')
            ->with($key)
            ->willReturn(true);

        $result = $this->service->exists($key);
        $this->assertTrue($result);
    }
}
