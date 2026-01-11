<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

declare(strict_types=1);

namespace Tests\CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Entity\EntityBase;
use MeekroDB;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test entity for verifying automatic values() generation.
 */
class TestEntityAutoValues extends EntityBase
{
    public const TABLE_NAME = 'test_table';

    #[Column(type: 'VARCHAR', length: 255)]
    public string $name;

    #[Column(type: 'INT', name: 'custom_column')]
    public int $customProperty;

    #[Column(type: 'VARCHAR', length: 100)]
    public string $camelCaseProperty;

    // Property without Column attribute - should not be included
    public string $nonPersistedProperty = 'test';
}

class EntityBaseTest extends TestCase
{
    private EntityBase $entity;
    private MeekroDB $dbMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->entity = new TestEntityAutoValues($this->dbMock);
    }

    public function testValuesMethodIncludesPropertiesWithColumnAttribute(): void
    {
        $this->entity->name = 'Test Name';
        $this->entity->customProperty = 42;
        $this->entity->camelCaseProperty = 'test value';

        $reflection = new ReflectionClass($this->entity);
        $method = $reflection->getMethod('values');
        $method->setAccessible(true);
        $values = $method->invoke($this->entity);

        $this->assertArrayHasKey('name', $values);
        $this->assertEquals('Test Name', $values['name']);
    }

    public function testValuesMethodUsesCustomColumnName(): void
    {
        $this->entity->customProperty = 42;

        $reflection = new ReflectionClass($this->entity);
        $method = $reflection->getMethod('values');
        $method->setAccessible(true);
        $values = $method->invoke($this->entity);

        $this->assertArrayHasKey('custom_column', $values);
        $this->assertEquals(42, $values['custom_column']);
        $this->assertArrayNotHasKey('customProperty', $values);
    }

    public function testValuesMethodConvertsPropertyNameToSnakeCase(): void
    {
        $this->entity->camelCaseProperty = 'test value';

        $reflection = new ReflectionClass($this->entity);
        $method = $reflection->getMethod('values');
        $method->setAccessible(true);
        $values = $method->invoke($this->entity);

        $this->assertArrayHasKey('camel_case_property', $values);
        $this->assertEquals('test value', $values['camel_case_property']);
    }

    public function testValuesMethodExcludesPropertiesWithoutColumnAttribute(): void
    {
        $this->entity->name = 'Test Name';
        $this->entity->nonPersistedProperty = 'should not be included';

        $reflection = new ReflectionClass($this->entity);
        $method = $reflection->getMethod('values');
        $method->setAccessible(true);
        $values = $method->invoke($this->entity);

        $this->assertArrayNotHasKey('nonPersistedProperty', $values);
        $this->assertArrayNotHasKey('non_persisted_property', $values);
    }

    public function testValuesMethodExcludesPrimaryKeyWithAutoIncrement(): void
    {
        $this->entity->id = 123;
        $this->entity->name = 'Test Name';

        $reflection = new ReflectionClass($this->entity);
        $method = $reflection->getMethod('values');
        $method->setAccessible(true);
        $values = $method->invoke($this->entity);

        $this->assertArrayNotHasKey('id', $values);
    }

    public function testToSnakeCaseConversion(): void
    {
        $reflection = new ReflectionClass($this->entity);
        $method = $reflection->getMethod('toSnakeCase');
        $method->setAccessible(true);

        $this->assertEquals('camel_case', $method->invoke($this->entity, 'camelCase'));
        $this->assertEquals('pascal_case', $method->invoke($this->entity, 'PascalCase'));
        $this->assertEquals('multiple_words_here', $method->invoke($this->entity, 'multipleWordsHere'));
        $this->assertEquals('simple', $method->invoke($this->entity, 'simple'));
    }

    public function testLoadFromValuesHydratesEntityFromDatabaseRow(): void
    {
        $values = [
            'id' => 123,
            'name' => 'Test Name',
            'custom_column' => 42,
            'camel_case_property' => 'test value',
        ];

        $this->entity->loadFromValues($values);

        $this->assertEquals(123, $this->entity->id);
        $this->assertEquals('Test Name', $this->entity->name);
        $this->assertEquals(42, $this->entity->customProperty);
        $this->assertEquals('test value', $this->entity->camelCaseProperty);
    }

    public function testLoadFromValuesUsesCustomColumnNames(): void
    {
        $values = [
            'custom_column' => 99,
        ];

        $this->entity->loadFromValues($values);

        $this->assertEquals(99, $this->entity->customProperty);
    }

    public function testLoadFromValuesIgnoresNonExistentColumns(): void
    {
        $values = [
            'id' => 123,
            'name' => 'Test Name',
            'non_existent_column' => 'should be ignored',
        ];

        $this->entity->loadFromValues($values);

        $this->assertEquals(123, $this->entity->id);
        $this->assertEquals('Test Name', $this->entity->name);
    }

    public function testLoadFromValuesReturnsEntityInstance(): void
    {
        $values = [
            'id' => 123,
            'name' => 'Test Name',
        ];

        $result = $this->entity->loadFromValues($values);

        $this->assertSame($this->entity, $result);
    }

    public function testGetTableNameReturnsConstantValue(): void
    {
        $tableName = TestEntityAutoValues::getTableName();

        $this->assertEquals('test_table', $tableName);
    }

    public function testGetTableNameUsesLateStaticBinding(): void
    {
        // Verify that the constant can be accessed statically without instantiation
        $this->assertEquals('test_table', TestEntityAutoValues::getTableName());
        $this->assertEquals(TestEntityAutoValues::TABLE_NAME, TestEntityAutoValues::getTableName());
    }

    public function testGetTableNameThrowsExceptionWhenConstantNotDefined(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must define a TABLE_NAME constant');

        // Create an anonymous class without TABLE_NAME constant
        $entityClass = new class ($this->dbMock) extends EntityBase {
            // No TABLE_NAME constant defined
        };

        // Call getTableName on the class, not the instance
        $className = get_class($entityClass);
        $className::getTableName();
    }
}
