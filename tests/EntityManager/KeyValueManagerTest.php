<?php

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\KeyValueManager;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KeyValueManagerTest extends TestCase
{
    protected MeekroDB&MockObject $db;
    protected EntityFactory&MockObject $entityFactory;
    protected KeyValueManager $manager;

    protected function setUp(): void
    {
        $this->db = $this->createMock(MeekroDB::class);
        $this->entityFactory = $this->createMock(EntityFactory::class);
        $this->manager = new KeyValueManager($this->db, $this->entityFactory);
    }

    public function testGetReturnsValueWhenExists(): void
    {
        $key = 'test.key';
        $value = 'test_value';
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => $value,
            'type' => 'string',
        ];

        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT * FROM key_values WHERE key_name = %s", $key)
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertEquals($value, $result);
    }

    public function testGetReturnsDefaultWhenNotExists(): void
    {
        $key = 'nonexistent.key';
        $default = 'default_value';

        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT * FROM key_values WHERE key_name = %s", $key)
            ->willReturn(null);

        $result = $this->manager->get($key, $default);
        $this->assertEquals($default, $result);
    }

    public function testGetCastsIntValue(): void
    {
        $key = 'test.int';
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => '42',
            'type' => 'int',
        ];

        $this->db->method('queryFirstRow')
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertSame(42, $result);
        $this->assertIsInt($result);
    }

    public function testGetCastsFloatValue(): void
    {
        $key = 'test.float';
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => '3.14',
            'type' => 'float',
        ];

        $this->db->method('queryFirstRow')
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertSame(3.14, $result);
        $this->assertIsFloat($result);
    }

    public function testGetCastsBoolValue(): void
    {
        $key = 'test.bool';
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => '1',
            'type' => 'bool',
        ];

        $this->db->method('queryFirstRow')
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    public function testGetCastsBoolFalseValue(): void
    {
        $key = 'test.bool';
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => '0',
            'type' => 'bool',
        ];

        $this->db->method('queryFirstRow')
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    public function testGetCastsArrayValue(): void
    {
        $key = 'test.array';
        $expectedArray = ['foo' => 'bar', 'baz' => 123];
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => json_encode($expectedArray),
            'type' => 'array',
        ];

        $this->db->method('queryFirstRow')
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertEquals($expectedArray, $result);
        $this->assertIsArray($result);
    }

    public function testGetCastsNullValue(): void
    {
        $key = 'test.null';
        $row = [
            'id' => 1,
            'key_name' => $key,
            'value' => null,
            'type' => 'null',
        ];

        $this->db->method('queryFirstRow')
            ->willReturn($row);

        $result = $this->manager->get($key);
        $this->assertNull($result);
    }

    public function testSetInsertsNewKey(): void
    {
        $key = 'new.key';
        $value = 'new_value';

        // Mock checking if key exists (returns null = doesn't exist)
        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT id FROM key_values WHERE key_name = %s", $key)
            ->willReturn(null);

        // Mock insert
        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'key_values',
                [
                    'key_name' => $key,
                    'value' => $value,
                    'type' => 'string',
                ]
            );

        $this->manager->set($key, $value);
    }

    public function testSetUpdatesExistingKey(): void
    {
        $key = 'existing.key';
        $value = 'updated_value';
        $existingId = 5;

        // Mock checking if key exists (returns existing row)
        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT id FROM key_values WHERE key_name = %s", $key)
            ->willReturn(['id' => $existingId]);

        // Mock update
        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'key_values',
                [
                    'value' => $value,
                    'type' => 'string',
                ],
                'id=%i',
                $existingId
            );

        $this->manager->set($key, $value);
    }

    public function testSetStoresIntValue(): void
    {
        $key = 'test.int';
        $value = 42;

        $this->db->method('queryFirstRow')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'key_values',
                [
                    'key_name' => $key,
                    'value' => '42',
                    'type' => 'int',
                ]
            );

        $this->manager->set($key, $value);
    }

    public function testSetStoresFloatValue(): void
    {
        $key = 'test.float';
        $value = 3.14;

        $this->db->method('queryFirstRow')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'key_values',
                [
                    'key_name' => $key,
                    'value' => '3.14',
                    'type' => 'float',
                ]
            );

        $this->manager->set($key, $value);
    }

    public function testSetStoresBoolValue(): void
    {
        $key = 'test.bool';
        $value = true;

        $this->db->method('queryFirstRow')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'key_values',
                [
                    'key_name' => $key,
                    'value' => '1',
                    'type' => 'bool',
                ]
            );

        $this->manager->set($key, $value);
    }

    public function testSetStoresArrayValue(): void
    {
        $key = 'test.array';
        $value = ['foo' => 'bar', 'baz' => 123];

        $this->db->method('queryFirstRow')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'key_values',
                [
                    'key_name' => $key,
                    'value' => json_encode($value),
                    'type' => 'array',
                ]
            );

        $this->manager->set($key, $value);
    }

    public function testSetStoresNullValue(): void
    {
        $key = 'test.null';
        $value = null;

        $this->db->method('queryFirstRow')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'key_values',
                [
                    'key_name' => $key,
                    'value' => null,
                    'type' => 'null',
                ]
            );

        $this->manager->set($key, $value);
    }

    public function testDeleteRemovesKey(): void
    {
        $key = 'test.key';

        $this->db->expects($this->once())
            ->method('delete')
            ->with('key_values', 'key_name=%s', $key);

        $this->manager->deleteByKey($key);
    }

    public function testExistsReturnsTrueWhenKeyExists(): void
    {
        $key = 'existing.key';

        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT id FROM key_values WHERE key_name = %s", $key)
            ->willReturn(['id' => 1]);

        $result = $this->manager->existsByKey($key);
        $this->assertTrue($result);
    }

    public function testExistsReturnsFalseWhenKeyDoesNotExist(): void
    {
        $key = 'nonexistent.key';

        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT id FROM key_values WHERE key_name = %s", $key)
            ->willReturn(null);

        $result = $this->manager->existsByKey($key);
        $this->assertFalse($result);
    }
}
