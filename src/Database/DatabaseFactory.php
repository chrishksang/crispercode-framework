<?php

declare(strict_types=1);

namespace CrisperCode\Database;

use CrisperCode\Config\FrameworkConfig;
use MeekroDB;

/**
 * Factory for creating MeekroDB database connections.
 *
 * This factory centralizes database connection creation and configuration,
 * reading connection parameters from FrameworkConfig. Supports MySQL, SQLite,
 * and PostgreSQL database drivers.
 */
class DatabaseFactory
{
    /**
     * Creates a new MeekroDB database connection instance.
     *
     * Supports multiple database drivers:
     * - mysql: MySQL/MariaDB databases
     * - sqlite: SQLite databases (file-based or in-memory)
     * - pgsql/postgres/postgresql: PostgreSQL databases
     *
     * @param FrameworkConfig $config The framework configuration containing database credentials.
     * @return MeekroDB The configured database connection instance.
     * @throws \InvalidArgumentException If an unsupported database driver is specified.
     */
    public static function create(FrameworkConfig $config): MeekroDB
    {
        // If a custom DSN is provided, use it directly
        $customDsn = $config->getDbDsn();
        if ($customDsn !== null) {
            return new MeekroDB(
                $customDsn,
                $config->getDbUser(),
                $config->getDbPassword()
            );
        }

        // Build DSN based on driver
        $driver = strtolower($config->getDbDriver());
        $dsn = self::buildDsn($driver, $config);

        return new MeekroDB(
            $dsn,
            $config->getDbUser(),
            $config->getDbPassword()
        );
    }

    /**
     * Builds a DSN string for the specified database driver.
     *
     * @param string $driver The database driver (mysql, sqlite, pgsql, etc.).
     * @param FrameworkConfig $config The framework configuration.
     * @return string The DSN string.
     * @throws \InvalidArgumentException If an unsupported database driver is specified.
     */
    private static function buildDsn(string $driver, FrameworkConfig $config): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => self::buildMysqlDsn($config),
            'sqlite' => self::buildSqliteDsn($config),
            'pgsql', 'postgres', 'postgresql' => self::buildPostgresDsn($config),
            default => throw new \InvalidArgumentException(
                "Unsupported database driver: $driver. " .
                "Supported drivers: mysql, sqlite, pgsql"
            ),
        };
    }

    /**
     * Builds a MySQL/MariaDB DSN string.
     *
     * @param FrameworkConfig $config The framework configuration.
     * @return string The MySQL DSN string.
     */
    private static function buildMysqlDsn(FrameworkConfig $config): string
    {
        return sprintf(
            'mysql:host=%s;dbname=%s;port=%d;charset=utf8mb4',
            $config->getDbHost(),
            $config->getDbName(),
            $config->getDbPort()
        );
    }

    /**
     * Builds a SQLite DSN string.
     *
     * For SQLite, the dbName is treated as the file path.
     * Special values:
     * - ":memory:" creates an in-memory database
     * - Empty string defaults to ":memory:"
     * - Otherwise, treated as a file path (including "0" which is a valid filename)
     *
     * @param FrameworkConfig $config The framework configuration.
     * @return string The SQLite DSN string.
     */
    private static function buildSqliteDsn(FrameworkConfig $config): string
    {
        $dbName = $config->getDbName();

        // Default to in-memory if no database name specified
        if ($dbName === '') {
            return 'sqlite::memory:';
        }

        // Use the database name directly (could be :memory: or a file path)
        return 'sqlite:' . $dbName;
    }

    /**
     * Builds a PostgreSQL DSN string.
     *
     * @param FrameworkConfig $config The framework configuration.
     * @return string The PostgreSQL DSN string.
     */
    private static function buildPostgresDsn(FrameworkConfig $config): string
    {
        return sprintf(
            'pgsql:host=%s;dbname=%s;port=%d',
            $config->getDbHost(),
            $config->getDbName(),
            $config->getDbPort()
        );
    }
}
