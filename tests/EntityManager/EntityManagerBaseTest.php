<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\EntityBase;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\EntityManagerBase;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mock entity with an invalid table name for testing validation.
 */
class InvalidTableEntity
{
    public static function getTableName(): string
    {
        return 'invalid-table!'; // Invalid characters
    }
}

/**
 * Tests for EntityManagerBase.
 */
class EntityManagerBaseTest extends TestCase
{
    private MeekroDB&MockObject $db;
    private EntityFactory&MockObject $entityFactory;
    private EntityManagerBase $entityManager;

    protected function setUp(): void
    {
        $this->db = $this->createMock(MeekroDB::class);
        $this->entityFactory = $this->createMock(EntityFactory::class);

        // Create a concrete implementation for testing
        $this->entityManager = new #[EntityManagerAttribute(entityClass: Ticker::class)]
        class ($this->db, $this->entityFactory) extends EntityManagerBase {
        };
    }

    public function testLoadCallsEntityFactory(): void
    {
        $id = 1;
        $row = [
            'id' => $id,
            'epic' => 'TEST',
            'name' => 'Test Ticker',
            'instrument_type' => 'STOCK',
            'source' => 'API',
            'market_hours' => 'US',
        ];

        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT * FROM tickers WHERE id = %i", $id)
            ->willReturn($row);

        $ticker = new Ticker($this->db);
        $ticker->loadFromValues($row);

        $this->entityFactory->expects($this->once())
            ->method('create')
            ->with(Ticker::class, $row)
            ->willReturn($ticker);

        $result = $this->entityManager->load($id);

        $this->assertInstanceOf(Ticker::class, $result);
        $this->assertEquals($id, $result->id);
    }

    public function testLoadReturnsNullWhenNotFound(): void
    {
        $id = 999;

        $this->db->expects($this->once())
            ->method('queryFirstRow')
            ->with("SELECT * FROM tickers WHERE id = %i", $id)
            ->willReturn(null);

        $this->entityFactory->expects($this->never())
            ->method('create');

        $result = $this->entityManager->load($id);

        $this->assertNull($result);
    }

    public function testLoadValidatesTableName(): void
    {
        // Create a manager with an entity that has an invalid table name
        $invalidManager = new #[EntityManagerAttribute(entityClass: InvalidTableEntity::class)]
        class ($this->db, $this->entityFactory) extends EntityManagerBase {
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name');

        $invalidManager->load(1);
    }

    public function testLoadFromValuesUsesEntityFactory(): void
    {
        $row = [
            'id' => 1,
            'epic' => 'TEST',
            'name' => 'Test Ticker',
        ];

        $ticker = new Ticker($this->db);
        $ticker->loadFromValues($row);

        $this->entityFactory->expects($this->once())
            ->method('create')
            ->with(Ticker::class, $row)
            ->willReturn($ticker);

        // Use reflection to access the protected method
        $reflection = new \ReflectionClass($this->entityManager);
        $method = $reflection->getMethod('loadFromValues');
        $method->setAccessible(true);

        $result = $method->invoke($this->entityManager, $row);

        $this->assertInstanceOf(Ticker::class, $result);
    }

    public function testLoadMultipleWithPagination(): void
    {
        $rows = [
            [
                'id' => 1,
                'epic' => 'TEST1',
                'name' => 'Test Ticker 1',
                'instrument_type' => 'STOCK',
                'source' => 'API',
                'market_hours' => 'US',
            ],
            [
                'id' => 2,
                'epic' => 'TEST2',
                'name' => 'Test Ticker 2',
                'instrument_type' => 'STOCK',
                'source' => 'API',
                'market_hours' => 'US',
            ],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with("SELECT * FROM tickers LIMIT 10 OFFSET 5")
            ->willReturn($rows);

        $ticker1 = new Ticker($this->db);
        $ticker1->loadFromValues($rows[0]);
        $ticker2 = new Ticker($this->db);
        $ticker2->loadFromValues($rows[1]);

        $this->entityFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($ticker1, $ticker2);

        $result = $this->entityManager->loadMultiple([
            'ids' => [],
            'limit' => 10,
            'offset' => 5,
        ]);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Ticker::class, $result[0]);
        $this->assertInstanceOf(Ticker::class, $result[1]);
    }

    public function testCount(): void
    {
        $this->db->expects($this->once())
            ->method('queryFirstField')
            ->with("SELECT COUNT(*) FROM tickers")
            ->willReturn(42);

        $result = $this->entityManager->count();

        $this->assertEquals(42, $result);
    }

    public function testDelete(): void
    {
        $id = 123;

        $this->db->expects($this->once())
            ->method('delete')
            ->with('tickers', 'id=%i', $id);

        $this->entityManager->delete($id);
    }

    public function testConstructorRequiresEntityManagerAttribute(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be decorated with #[EntityManagerAttribute(...)]');

        // Create a manager without the attribute
        new class ($this->db, $this->entityFactory) extends EntityManagerBase {
        };
    }
}

class Ticker extends EntityBase
{
    public const TABLE_NAME = 'tickers';

    #[Column(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Column(type: 'VARCHAR')]
    public string $epic;

    #[Column(type: 'VARCHAR')]
    public string $name;

    #[Column(type: 'VARCHAR')]
    public string $instrument_type;

    #[Column(type: 'VARCHAR')]
    public string $source;

    #[Column(type: 'VARCHAR')]
    public string $market_hours;
}
