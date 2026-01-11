<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Database;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;
use CrisperCode\Config\FrameworkConfig;
use CrisperCode\Database\DatabaseFactory;
use CrisperCode\Database\SchemaManager;
use CrisperCode\Entity\EntityBase;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for multi-database support.
 *
 * These tests verify that the framework can actually work with different
 * database drivers, not just create connections.
 */
#[Index(columns: ['email'], unique: true)]
class TestUser extends EntityBase
{
    public const TABLE_NAME = 'test_users';

    #[Column(type: 'VARCHAR', length: 255)]
    public string $email;

    #[Column(type: 'VARCHAR', length: 100)]
    public string $name;

    #[Column(type: 'INT', nullable: true)]
    public ?int $age = null;

    #[Column(type: 'DATETIME')]
    public string $createdAt;
}

class MultiDatabaseIntegrationTest extends TestCase
{
    /**
     * Tests SQLite in-memory database operations.
     */
    public function testSQLiteInMemoryOperations(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: ':memory:',
        );

        $db = DatabaseFactory::create($config);
        $this->assertNotNull($db);

        // Create schema
        $schemaManager = new SchemaManager($db);
        $schemaManager->setQuiet(true);
        $schemaManager->syncTable(TestUser::class);

        // Insert test data
        $db->insert('test_users', [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'age' => 30,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Query data
        $user = $db->queryFirstRow('SELECT * FROM test_users WHERE id = %i', 1);
        $this->assertNotNull($user);
        $this->assertEquals('test@example.com', $user['email']);
        $this->assertEquals('Test User', $user['name']);
        $this->assertEquals(30, $user['age']);
    }

    /**
     * Tests that SQLite file-based database can be created.
     */
    public function testSQLiteFileDatabase(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $dbPath = '/tmp/test_' . uniqid() . '.db';

        try {
            $config = new FrameworkConfig(
                rootPath: '/tmp',
                dbDriver: 'sqlite',
                dbName: $dbPath,
            );

            $db = DatabaseFactory::create($config);
            $this->assertNotNull($db);

            // Create schema
            $schemaManager = new SchemaManager($db);
            $schemaManager->setQuiet(true);
            $schemaManager->syncTable(TestUser::class);

            // Verify table exists
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test_users'");
            $this->assertCount(1, $tables);

            // Verify file was created
            $this->assertFileExists($dbPath);
        } finally {
            // Cleanup
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    /**
     * Tests that custom DSN works.
     */
    public function testCustomDSN(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDsn: 'sqlite::memory:',
        );

        $db = DatabaseFactory::create($config);
        $this->assertNotNull($db);

        // Create schema
        $schemaManager = new SchemaManager($db);
        $schemaManager->setQuiet(true);
        $schemaManager->syncTable(TestUser::class);

        // Verify table exists
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test_users'");
        $this->assertCount(1, $tables);
    }

    /**
     * Tests that the driver is correctly detected.
     */
    public function testDriverDetection(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: ':memory:',
        );

        $db = DatabaseFactory::create($config);
        $pdo = $db->get();

        $this->assertNotNull($pdo);
        $this->assertEquals('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Tests that ENUM types are converted to TEXT in SQLite.
     */
    public function testSQLiteEnumConversion(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: ':memory:',
        );

        $db = DatabaseFactory::create($config);

        $testEntity = new class ($db) extends EntityBase {
            public const TABLE_NAME = 'enum_test_sqlite';

            #[Column(type: 'ENUM', length: "'active','inactive'")]
            public string $status;
        };

        $schemaManager = new SchemaManager($db);
        $schemaManager->setQuiet(true);
        $schemaManager->syncTable($testEntity::class);

        // Get column info
        $columns = $db->query("PRAGMA table_info(`enum_test_sqlite`)");
        $statusColumn = array_filter($columns, fn($col) => $col['name'] === 'status');
        $statusColumn = reset($statusColumn);

        $this->assertNotEmpty($statusColumn);
        // SQLite should convert ENUM to TEXT
        $this->assertEquals('TEXT', strtoupper($statusColumn['type']));
    }

    /**
     * Tests that indexes are created correctly in SQLite.
     */
    public function testSQLiteIndexCreation(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension not available');
        }

        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: ':memory:',
        );

        $db = DatabaseFactory::create($config);
        $schemaManager = new SchemaManager($db);
        $schemaManager->setQuiet(true);
        $schemaManager->syncTable(TestUser::class);

        // Check indexes
        $indexes = $db->query("PRAGMA index_list(`test_users`)");

        // Should have unique index on email
        $emailIndex = array_filter($indexes, function ($idx) {
            return !str_starts_with($idx['name'], 'sqlite_autoindex_');
        });

        $this->assertNotEmpty($emailIndex);
    }
}
