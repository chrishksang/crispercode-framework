<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Cache;

use CrisperCode\Cache\Cache;
use CrisperCode\Cache\CacheBackendInterface;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    protected CacheBackendInterface $backend;
    protected Cache $cache;

    protected function setUp(): void
    {
        $this->backend = $this->createMock(CacheBackendInterface::class);
        $this->cache = new Cache($this->backend);
    }

    public function testGetDelegatesToBackend(): void
    {
        $key = 'test_key';
        $value = 'test_value';

        $this->backend->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn($value);

        $result = $this->cache->get($key);
        $this->assertEquals($value, $result);
    }

    public function testGetReturnsNullWhenBackendReturnsNull(): void
    {
        $key = 'non_existent_key';

        $this->backend->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn(null);

        $result = $this->cache->get($key);
        $this->assertNull($result);
    }

    public function testSetDelegatesToBackend(): void
    {
        $key = 'test_key';
        $value = 'test_value';
        $expiresIn = 3600;

        $this->backend->expects($this->once())
            ->method('set')
            ->with($key, $value, $expiresIn);

        $this->cache->set($key, $value, $expiresIn);
    }

    public function testSetUsesDefaultExpiry(): void
    {
        $key = 'test_key';
        $value = 'test_value';

        $this->backend->expects($this->once())
            ->method('set')
            ->with($key, $value, 86400);

        $this->cache->set($key, $value);
    }

    public function testInvalidateDelegatesToBackend(): void
    {
        $pattern = 'test_%';

        $this->backend->expects($this->once())
            ->method('invalidate')
            ->with($pattern);

        $this->cache->invalidate($pattern);
    }
}
