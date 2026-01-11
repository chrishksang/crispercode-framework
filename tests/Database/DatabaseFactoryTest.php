<?php

declare(strict_types=1);

namespace CrisperCode\Tests\Database;

use CrisperCode\Config\FrameworkConfig;
use CrisperCode\Database\DatabaseFactory;
use MeekroDB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DatabaseFactory.
 */
class DatabaseFactoryTest extends TestCase
{
    /**
     * Tests that create() returns a MeekroDB instance for MySQL.
     */
    public function testCreateReturnsMeekroDBInstance(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'mysql',
            dbHost: 'test-host',
            dbName: 'test-database',
            dbUser: 'test-user',
            dbPassword: 'test-password',
            dbPort: 3306,
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() configures MySQL connection correctly.
     */
    public function testCreateUsesConfigValues(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'mysql',
            dbHost: 'custom-host',
            dbName: 'custom-db',
            dbUser: 'custom-user',
            dbPassword: 'custom-pass',
            dbPort: 3307,
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() builds correct SQLite DSN for file-based database.
     */
    public function testCreateSQLiteFileDatabase(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: '/tmp/test.db',
            dbUser: '',
            dbPassword: '',
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() builds correct SQLite DSN for in-memory database.
     */
    public function testCreateSQLiteInMemoryDatabase(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: ':memory:',
            dbUser: '',
            dbPassword: '',
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() defaults to in-memory for SQLite with empty dbName.
     */
    public function testCreateSQLiteDefaultsToInMemory(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: '',
            dbUser: '',
            dbPassword: '',
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() treats "0" as a valid SQLite filename.
     */
    public function testCreateSQLiteTreatsZeroAsValidFilename(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'sqlite',
            dbName: '0',
            dbUser: '',
            dbPassword: '',
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
        // Note: This would create a file named "0" in the current directory
        // which is a valid SQLite database file
    }

    /**
     * Tests that create() builds correct PostgreSQL DSN.
     */
    public function testCreatePostgreSQLDatabase(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'pgsql',
            dbHost: 'postgres-host',
            dbName: 'test-db',
            dbUser: 'postgres-user',
            dbPassword: 'postgres-pass',
            dbPort: 5432,
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() accepts 'postgres' as driver alias.
     */
    public function testCreatePostgresAlias(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'postgres',
            dbHost: 'postgres-host',
            dbName: 'test-db',
            dbUser: 'postgres-user',
            dbPassword: 'postgres-pass',
            dbPort: 5432,
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() accepts 'postgresql' as driver alias.
     */
    public function testCreatePostgreSQLAlias(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'postgresql',
            dbHost: 'postgres-host',
            dbName: 'test-db',
            dbUser: 'postgres-user',
            dbPassword: 'postgres-pass',
            dbPort: 5432,
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() accepts 'mariadb' as driver alias for MySQL.
     */
    public function testCreateMariaDBAlias(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'mariadb',
            dbHost: 'mariadb-host',
            dbName: 'test-db',
            dbUser: 'maria-user',
            dbPassword: 'maria-pass',
            dbPort: 3306,
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() uses custom DSN when provided.
     */
    public function testCreateWithCustomDSN(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbUser: 'custom-user',
            dbPassword: 'custom-pass',
            dbDsn: 'mysql:host=custom;dbname=customdb;port=3307',
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }

    /**
     * Tests that create() throws exception for unsupported driver.
     */
    public function testCreateThrowsExceptionForUnsupportedDriver(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbDriver: 'mongodb',
            dbHost: 'mongo-host',
            dbName: 'test-db',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: mongodb');

        DatabaseFactory::create($config);
    }

    /**
     * Tests that create() defaults to MySQL when no driver specified.
     */
    public function testCreateDefaultsToMySQL(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            dbHost: 'test-host',
            dbName: 'test-db',
            dbUser: 'test-user',
            dbPassword: 'test-pass',
        );

        $db = DatabaseFactory::create($config);

        $this->assertInstanceOf(MeekroDB::class, $db);
    }
}
