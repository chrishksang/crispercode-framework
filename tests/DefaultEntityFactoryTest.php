<?php

declare(strict_types=1);

namespace Tests\CrisperCode;

use CrisperCode\EntityFactory;
use CrisperCode\Entity\KeyValue;
use MeekroDB;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for DefaultEntityFactory.
 */
class DefaultEntityFactoryTest extends TestCase
{
    private MeekroDB&MockObject $dbMock;
    private EntityFactory $entityFactory;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->entityFactory = new EntityFactory($this->dbMock);
    }

    public function testCreateReturnsEntityInstance(): void
    {
        $entity = $this->entityFactory->create(KeyValue::class);

        $this->assertInstanceOf(KeyValue::class, $entity);
    }

    public function testCreateWithEmptyDataReturnsUninitializedEntity(): void
    {
        $entity = $this->entityFactory->create(KeyValue::class);

        $this->assertInstanceOf(KeyValue::class, $entity);
        // Entity should exist but properties should not be initialized
        $this->assertFalse(isset($entity->keyName));
    }

    public function testCreateWithDataHydratesEntity(): void
    {
        $data = [
            'id' => 1,
            'key_name' => 'test_key',
            'value' => 'test_value',
            'type' => 'string',
        ];

        $entity = $this->entityFactory->create(KeyValue::class, $data);

        $this->assertInstanceOf(KeyValue::class, $entity);
        $this->assertEquals(1, $entity->id);
        $this->assertEquals('test_key', $entity->keyName);
        $this->assertEquals('test_value', $entity->value);
        $this->assertEquals('string', $entity->type);
    }

    public function testCreateWithPartialDataHydratesOnlyProvidedFields(): void
    {
        $data = [
            'key_name' => 'test_key',
        ];

        $entity = $this->entityFactory->create(KeyValue::class, $data);

        $this->assertEquals('test_key', $entity->keyName);
        $this->assertFalse(isset($entity->value));
    }

    public function testCreatedEntityCanBeSaved(): void
    {
        $data = [
            'key_name' => 'test_key',
            'value' => 'test_value',
            'type' => 'string',
        ];

        $this->dbMock->expects($this->once())
            ->method('insert')
            ->with('key_values', $this->isType('array'));

        $this->dbMock->expects($this->once())
            ->method('insertId')
            ->willReturn(42);

        $entity = $this->entityFactory->create(KeyValue::class, $data);
        $id = $entity->save();

        $this->assertEquals(42, $id);
    }

    public function testCreateMultipleEntitiesInLoop(): void
    {
        $apiData = [
            [
                'key_name' => 'key1',
                'value' => 'value1',
                'type' => 'string',
            ],
            [
                'key_name' => 'key2',
                'value' => 'value2',
                'type' => 'string',
            ],
        ];

        $entities = [];
        foreach ($apiData as $data) {
            $entities[] = $this->entityFactory->create(KeyValue::class, $data);
        }

        $this->assertCount(2, $entities);
        $this->assertInstanceOf(KeyValue::class, $entities[0]);
        $this->assertInstanceOf(KeyValue::class, $entities[1]);
        $this->assertEquals('key1', $entities[0]->keyName);
        $this->assertEquals('key2', $entities[1]->keyName);
    }

    public function testFindByIdReturnsEntityWhenFound(): void
    {
        $row = [
            'id' => 42,
            'key_name' => 'test_key',
            'value' => 'test_value',
            'type' => 'string',
        ];

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT * FROM %b WHERE id = %i", 'key_values', 42)
            ->willReturn($row);

        $entity = $this->entityFactory->findById(KeyValue::class, 42);

        $this->assertInstanceOf(KeyValue::class, $entity);
        $this->assertEquals(42, $entity->id);
        $this->assertEquals('test_key', $entity->keyName);
        $this->assertEquals('test_value', $entity->value);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT * FROM %b WHERE id = %i", 'key_values', 999)
            ->willReturn(null);

        $entity = $this->entityFactory->findById(KeyValue::class, 999);

        $this->assertNull($entity);
    }

    public function testFindOneByReturnsEntityWhenFound(): void
    {
        $row = [
            'id' => 1,
            'key_name' => 'unique_key',
            'value' => 'unique_value',
            'type' => 'string',
        ];

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($row);

        $entity = $this->entityFactory->findOneBy(KeyValue::class, ['key_name' => 'unique_key']);

        $this->assertInstanceOf(KeyValue::class, $entity);
        $this->assertEquals('unique_key', $entity->keyName);
        $this->assertEquals('unique_value', $entity->value);
    }

    public function testFindOneByReturnsNullWhenNotFound(): void
    {
        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn(null);

        $entity = $this->entityFactory->findOneBy(KeyValue::class, ['key_name' => 'nonexistent']);

        $this->assertNull($entity);
    }

    public function testFindOneByWithMultipleCriteria(): void
    {
        $row = [
            'id' => 5,
            'key_name' => 'multi_key',
            'value' => 'multi_value',
            'type' => 'json',
        ];

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($row);

        $entity = $this->entityFactory->findOneBy(KeyValue::class, [
            'key_name' => 'multi_key',
            'type' => 'json',
        ]);

        $this->assertInstanceOf(KeyValue::class, $entity);
        $this->assertEquals('multi_key', $entity->keyName);
        $this->assertEquals('json', $entity->type);
    }

    public function testFindByIdUsesCorrectTableName(): void
    {
        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->with(
                "SELECT * FROM %b WHERE id = %i",
                'key_values',  // KeyValue::TABLE_NAME
                1
            )
            ->willReturn(null);

        $this->entityFactory->findById(KeyValue::class, 1);
    }
}
