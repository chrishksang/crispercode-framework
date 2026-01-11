<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

declare(strict_types=1);

namespace Tests\CrisperCode\Database;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;
use CrisperCode\Database\SchemaManager;
use CrisperCode\Entity\EntityBase;
use MeekroDB;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test entity for SchemaManager tests.
 */
#[Index(columns: ['name'], name: 'idx_test_name')]
#[Index(columns: ['name', 'status'], unique: true)]
class TestEntity extends EntityBase
{
    public const TABLE_NAME = 'test_entities';

    #[Column(type: 'VARCHAR', length: 255)]
    public string $name;

    #[Column(type: 'INT', nullable: true)]
    public ?int $status = null;

    #[Column(type: 'DECIMAL', precision: 10, scale: 2)]
    public float $price;

    #[Column(type: 'VARCHAR', length: 50, default: 'active')]
    public string $state = 'active';
}

/**
 * Test entity without tableName for error testing.
 */
class TestEntityNoTable extends EntityBase
{
}

class SchemaManagerTest extends TestCase
{
    private MockObject&MeekroDB $dbMock;
    private MockObject&PDO $pdoMock;
    private SchemaManager $manager;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->pdoMock = $this->createMock(PDO::class);
        $this->dbMock->method('get')->willReturn($this->pdoMock);
        $this->pdoMock->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('mysql');
        $this->manager = new SchemaManager($this->dbMock);
    }

    public function testSyncTableThrowsExceptionForNonExistentClass(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Class NonExistentClass does not exist');

        $this->manager->syncTable('NonExistentClass');
    }

    public function testSyncTableThrowsExceptionForEntityWithoutTableName(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not define TABLE_NAME constant');

        $this->manager->syncTable(TestEntityNoTable::class);
    }

    public function testSyncTableSkipsNonEntityClass(): void
    {
        // Should not throw any exception and not call db methods
        $this->dbMock->expects($this->never())->method('queryFirstColumn');

        $this->manager->syncTable(\stdClass::class);
    }

    public function testSyncTableCreatesNewTable(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn([]);

        // Expect CREATE TABLE to be called
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // Verify table name
                $this->assertStringContainsString('CREATE TABLE `test_entities`', $sql);
                // Verify columns from child class
                $this->assertStringContainsString('`name` VARCHAR(255) NOT NULL', $sql);
                $this->assertStringContainsString('`status` INT', $sql);
                $this->assertStringContainsString('`price` DECIMAL(10,2) NOT NULL', $sql);
                // Verify id from parent class
                $this->assertStringContainsString('`id` INT NOT NULL AUTO_INCREMENT', $sql);
                $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
                // Verify indexes
                $this->assertStringContainsString('KEY `idx_test_name` (`name`)', $sql);
                $this->assertStringContainsString('UNIQUE KEY `idx_test_entities_name_status`', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testSyncTableAddsNewColumns(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        // Existing columns (missing 'name' and 'price')
        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'status', 'Type' => 'int(11)', 'Null' => 'YES', 'Default' => null],
                        ['Field' => 'state', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Default' => 'active'],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                        ['Key_name' => 'idx_test_name', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'status'],
                    ];
                }
                return [];
            });

        $this->expectOutputRegex('/Adding column (name|price) to test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testSyncTableAddsNewIndexes(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        $queryCalls = [];
        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) use (&$queryCalls) {
                $queryCalls[] = $sql;
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'status', 'Type' => 'int(11)', 'Null' => 'YES', 'Default' => null],
                        ['Field' => 'price', 'Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'state', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Default' => 'active'],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    // Only PRIMARY KEY exists, no other indexes
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                    ];
                }
                return [];
            });

        $this->expectOutputRegex('/Adding index idx_test/');
        $this->manager->syncTable(TestEntity::class);

        // Check that index creation queries were made
        $indexQueries = array_filter($queryCalls, fn($sql) => str_starts_with($sql, 'CREATE'));
        $this->assertCount(2, $indexQueries);
    }

    public function testDefaultValueEscaping(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->willReturn([]);

        // Setup PDO quote mock for proper escaping
        $this->pdoMock->method('quote')
            ->with('active')
            ->willReturn("'active'");

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // Verify the default value is properly included
                $this->assertStringContainsString("DEFAULT 'active'", $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testIndexAutoGeneration(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->willReturn([]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // The second index has no name, should be auto-generated
                $this->assertStringContainsString('idx_test_entities_name_status', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testInheritedColumnFromParentClass(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->willReturn([]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // The 'id' column is defined in EntityBase parent class
                $this->assertStringContainsString('`id` INT NOT NULL AUTO_INCREMENT', $sql);
                $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testDecimalPrecisionAndScale(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->willReturn([]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // Verify DECIMAL has precision and scale
                $this->assertStringContainsString('DECIMAL(10,2)', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testNullableColumn(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->willReturn([]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // status is nullable, should not have NOT NULL
                // Check that name has NOT NULL
                $this->assertStringContainsString('`name` VARCHAR(255) NOT NULL', $sql);
                // Verify status column is present
                $this->assertStringContainsString('`status` INT', $sql);
                // Verify it doesn't contain "`status` INT NOT NULL"
                $this->assertStringNotContainsString('`status` INT NOT NULL', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testSetDryRunReturnsInstance(): void
    {
        $result = $this->manager->setDryRun(true);
        $this->assertSame($this->manager, $result);
    }

    public function testIsDryRunReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->manager->isDryRun());
    }

    public function testIsDryRunReturnsTrueWhenEnabled(): void
    {
        $this->manager->setDryRun(true);
        $this->assertTrue($this->manager->isDryRun());
    }

    public function testDryRunCreateTableDoesNotExecuteQuery(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn([]);

        // Query should never be called in dry run mode
        $this->dbMock->expects($this->never())->method('query');

        $this->manager->setDryRun(true);

        $this->expectOutputRegex('/\[DRY RUN\] Would create table test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testDryRunCreateTableOutputsSql(): void
    {
        // Table does not exist
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn([]);

        $this->dbMock->expects($this->never())->method('query');

        $this->manager->setDryRun(true);

        ob_start();
        $this->manager->syncTable(TestEntity::class);
        $output = ob_get_clean();

        // Verify that the output contains the SQL statement
        $this->assertStringContainsString('CREATE TABLE `test_entities`', $output);
        $this->assertStringContainsString('`name` VARCHAR(255) NOT NULL', $output);
        $this->assertStringContainsString('`id` INT NOT NULL AUTO_INCREMENT', $output);
    }

    public function testDryRunAddColumnDoesNotExecuteQuery(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        // Only allow SHOW COLUMNS and SHOW INDEX queries, not ALTER TABLE
        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                // Fail if ALTER TABLE is called
                $this->assertStringNotContainsString('ALTER TABLE', $sql);
                $this->assertStringNotContainsString('CREATE INDEX', $sql);
                $this->assertStringNotContainsString('CREATE UNIQUE INDEX', $sql);

                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                    ];
                }
                return [];
            });

        $this->manager->setDryRun(true);

        $this->expectOutputRegex('/\[DRY RUN\] Would add column name to test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testDryRunAddIndexDoesNotExecuteQuery(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                // Fail if CREATE INDEX is called
                $this->assertStringNotContainsString('CREATE INDEX', $sql);
                $this->assertStringNotContainsString('CREATE UNIQUE INDEX', $sql);

                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'status', 'Type' => 'int(11)', 'Null' => 'YES', 'Default' => null],
                        ['Field' => 'price', 'Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'state', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Default' => 'active'],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                    ];
                }
                return [];
            });

        $this->manager->setDryRun(true);

        $this->expectOutputRegex('/\[DRY RUN\] Would add index idx_test_name to test_entities/');
        $this->manager->syncTable(TestEntity::class);
    }

    public function testDryRunOutputsFullSqlForAddColumn(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                        ['Key_name' => 'idx_test_name', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'status'],
                    ];
                }
                return [];
            });

        $this->manager->setDryRun(true);

        ob_start();
        $this->manager->syncTable(TestEntity::class);
        $output = ob_get_clean();

        // Verify the SQL for adding column is shown
        $this->assertStringContainsString('ALTER TABLE `test_entities` ADD COLUMN', $output);
    }

    public function testExistingIndexWithMatchingColumnsProducesNoWarning(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    // All columns exist
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'status', 'Type' => 'int(11)', 'Null' => 'YES', 'Default' => null],
                        ['Field' => 'price', 'Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'state', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Default' => 'active'],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    // Indexes exist with matching columns
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                        ['Key_name' => 'idx_test_name', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'status'],
                    ];
                }
                return [];
            });

        ob_start();
        $this->manager->syncTable(TestEntity::class);
        $output = ob_get_clean();

        // Should not produce any warning since columns match
        $this->assertStringNotContainsString('Warning:', $output);
    }

    public function testExistingIndexWithDifferentColumnsProducesWarning(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    // All columns exist
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'status', 'Type' => 'int(11)', 'Null' => 'YES', 'Default' => null],
                        ['Field' => 'price', 'Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'state', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Default' => 'active'],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    // idx_test_name exists but on wrong column (status instead of name)
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                        ['Key_name' => 'idx_test_name', 'Column_name' => 'status'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'status'],
                    ];
                }
                return [];
            });

        ob_start();
        $this->manager->syncTable(TestEntity::class);
        $output = ob_get_clean();

        // Should produce a warning for the mismatched index
        $this->assertStringContainsString('Warning: Index `idx_test_name`', $output);
        $this->assertStringContainsString('exists but columns differ', $output);
        $this->assertStringContainsString('Expected: name', $output);
        $this->assertStringContainsString('Current: status', $output);
        $this->assertStringContainsString('Manual ALTER TABLE required', $output);
    }

    public function testExistingIndexWithDifferentColumnOrderProducesWarning(): void
    {
        // Table exists
        $this->dbMock->method('queryFirstColumn')
            ->with("SHOW TABLES LIKE %s", 'test_entities')
            ->willReturn(['test_entities']);

        $this->dbMock->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    // All columns exist
                    return [
                        ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'status', 'Type' => 'int(11)', 'Null' => 'YES', 'Default' => null],
                        ['Field' => 'price', 'Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => null],
                        ['Field' => 'state', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Default' => 'active'],
                    ];
                }
                if (str_starts_with($sql, 'SHOW INDEX')) {
                    // idx_test_entities_name_status exists but columns in wrong order
                    return [
                        ['Key_name' => 'PRIMARY', 'Column_name' => 'id'],
                        ['Key_name' => 'idx_test_name', 'Column_name' => 'name'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'status'],
                        ['Key_name' => 'idx_test_entities_name_status', 'Column_name' => 'name'],
                    ];
                }
                return [];
            });

        ob_start();
        $this->manager->syncTable(TestEntity::class);
        $output = ob_get_clean();

        // Should produce a warning because column order matters for indexes
        $this->assertStringContainsString('Warning: Index `idx_test_entities_name_status`', $output);
        $this->assertStringContainsString('exists but columns differ', $output);
        $this->assertStringContainsString('Expected: name, status', $output);
        $this->assertStringContainsString('Current: status, name', $output);
    }

    /**
     * Tests that SQLite tables are created with correct syntax.
     */
    public function testSyncTableCreatesSQLiteTable(): void
    {
        // Setup for SQLite - recreate mocks to avoid conflicts with setUp
        $dbMock = $this->createMock(MeekroDB::class);
        $pdoMock = $this->createMock(PDO::class);
        $dbMock->method('get')->willReturn($pdoMock);
        $pdoMock->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('sqlite');
        $manager = new SchemaManager($dbMock);

        // Table does not exist
        $dbMock->method('queryFirstRow')
            ->willReturn(null);

        $queryCalls = [];
        $dbMock->method('query')
            ->willReturnCallback(function (string $sql) use (&$queryCalls) {
                $queryCalls[] = $sql;
                return [];
            });

        $this->expectOutputRegex('/Creating table test_entities/');
        $manager->syncTable(TestEntity::class);

        // Verify SQLite-specific syntax
        $createTableQuery = $queryCalls[0] ?? '';
        $this->assertStringContainsString('CREATE TABLE `test_entities`', $createTableQuery);
        $this->assertStringContainsString('`id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT', $createTableQuery);
        // SQLite should not have ENGINE clause
        $this->assertStringNotContainsString('ENGINE=InnoDB', $createTableQuery);
    }

    /**
     * Tests that PostgreSQL tables are created with correct syntax.
     */
    public function testSyncTableCreatesPostgreSQLTable(): void
    {
        // Setup for PostgreSQL - recreate mocks to avoid conflicts with setUp
        $dbMock = $this->createMock(MeekroDB::class);
        $pdoMock = $this->createMock(PDO::class);
        $dbMock->method('get')->willReturn($pdoMock);
        $pdoMock->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('pgsql');
        $manager = new SchemaManager($dbMock);

        // Table does not exist
        $dbMock->method('queryFirstRow')
            ->willReturn(null);

        $queryCalls = [];
        $dbMock->method('query')
            ->willReturnCallback(function (string $sql) use (&$queryCalls) {
                $queryCalls[] = $sql;
                return [];
            });

        $this->expectOutputRegex('/Creating table test_entities/');
        $manager->syncTable(TestEntity::class);

        // Verify PostgreSQL-specific syntax
        $createTableQuery = $queryCalls[0] ?? '';
        $this->assertStringContainsString('CREATE TABLE `test_entities`', $createTableQuery);
        $this->assertStringContainsString('`id` SERIAL PRIMARY KEY', $createTableQuery);
        // PostgreSQL should not have ENGINE clause
        $this->assertStringNotContainsString('ENGINE=InnoDB', $createTableQuery);
    }

    /**
     * Tests that SQLite ENUM types are converted to TEXT.
     */
    public function testSQLiteConvertsEnumToText(): void
    {
        // Setup for SQLite - recreate mocks to avoid conflicts with setUp
        $dbMock = $this->createMock(MeekroDB::class);
        $pdoMock = $this->createMock(PDO::class);
        $dbMock->method('get')->willReturn($pdoMock);
        $pdoMock->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('sqlite');
        $manager = new SchemaManager($dbMock);

        // Create a test entity with ENUM column
        $testEntity = new class ($dbMock) extends EntityBase {
            public const TABLE_NAME = 'enum_test';

            #[Column(type: 'ENUM', length: "'active','inactive'")]
            public string $status;
        };

        // Table does not exist
        $dbMock->method('queryFirstRow')
            ->willReturn(null);

        $dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // SQLite should convert ENUM to TEXT
                $this->assertStringContainsString('`status` TEXT', $sql);
                $this->assertStringNotContainsString('ENUM', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table enum_test/');
        $manager->syncTable($testEntity::class);
    }

    /**
     * Tests that PostgreSQL ENUM types are converted to TEXT.
     */
    public function testPostgreSQLConvertsEnumToText(): void
    {
        // Setup for PostgreSQL - recreate mocks to avoid conflicts with setUp
        $dbMock = $this->createMock(MeekroDB::class);
        $pdoMock = $this->createMock(PDO::class);
        $dbMock->method('get')->willReturn($pdoMock);
        $pdoMock->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('pgsql');
        $manager = new SchemaManager($dbMock);

        // Create a test entity with ENUM column
        $testEntity = new class ($dbMock) extends EntityBase {
            public const TABLE_NAME = 'enum_test_pg';

            #[Column(type: 'ENUM', length: "'active','inactive'")]
            public string $status;
        };

        // Table does not exist
        $dbMock->method('queryFirstRow')
            ->willReturn(null);

        $dbMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function (string $sql): bool {
                // PostgreSQL should convert ENUM to TEXT
                $this->assertStringContainsString('`status` TEXT', $sql);
                $this->assertStringNotContainsString('ENUM', $sql);
                return true;
            }));

        $this->expectOutputRegex('/Creating table enum_test_pg/');
        $manager->syncTable($testEntity::class);
    }
}
