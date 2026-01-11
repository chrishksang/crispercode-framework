<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Cache;

use CrisperCode\Cache\DatabaseCacheBackend;
use MeekroDB;
use PHPUnit\Framework\TestCase;

class DatabaseCacheBackendTest extends TestCase
{
    protected MeekroDB $db;
    protected DatabaseCacheBackend $backend;

    protected function setUp(): void
    {
        $this->db = $this->createMock(MeekroDB::class);
        $this->backend = new DatabaseCacheBackend($this->db);
    }

    public function testGetReturnsValueWhenExists(): void
    {
        $key = 'test_key';
        $value = 'test_value';

        $this->db->expects($this->once())
            ->method('queryFirstField')
            ->with(
                'SELECT value FROM %l WHERE `name` = %s AND expires > %d',
                'cache',
                $key,
                $this->callback(function ($param) {
                    return is_int($param) && abs($param - time()) <= 5;
                })
            )
            ->willReturn($value);

        $result = $this->backend->get($key);
        $this->assertEquals($value, $result);
    }

    public function testGetReturnsNullWhenNotExists(): void
    {
        $key = 'non_existent_key';

        $this->db->expects($this->once())
            ->method('queryFirstField')
            ->willReturn(null);

        $result = $this->backend->get($key);
        $this->assertNull($result);
    }

    public function testGetReturnsNullForEmptyString(): void
    {
        $key = 'empty_key';

        $this->db->expects($this->once())
            ->method('queryFirstField')
            ->willReturn('');

        $result = $this->backend->get($key);
        $this->assertNull($result);
    }

    public function testSet(): void
    {
        $key = 'test_key';
        $value = 'test_value';
        $expiresIn = 3600;

        $this->db->expects($this->once())
            ->method('insertUpdate')
            ->with(
                'cache',
                $this->callback(function ($data) use ($key, $value, $expiresIn) {
                    return $data['name'] === $key
                        && $data['value'] === $value
                        && isset($data['expires'])
                        && abs(($data['expires'] - time()) - $expiresIn) <= 5;
                })
            );

        $this->backend->set($key, $value, $expiresIn);
    }

    public function testInvalidate(): void
    {
        $pattern = 'test_%';

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                'DELETE FROM %l WHERE `name` LIKE %ss',
                'cache',
                $pattern
            );

        $this->backend->invalidate($pattern);
    }
}
