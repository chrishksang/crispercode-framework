<?php

declare(strict_types=1);

namespace CrisperCode\Database;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;
use CrisperCode\Entity\EntityBase;
use MeekroDB;
use ReflectionClass;

/**
 * Manages database schema synchronization based on entity attributes.
 *
 * This service reads Column and Index attributes from entity classes and
 * synchronizes the database schema accordingly. It supports:
 * - Creating new tables with columns and indexes
 * - Adding new columns to existing tables
 * - Creating new indexes on existing tables
 *
 * Note: Modifying existing columns is not currently supported. If column
 * definitions change, manual ALTER TABLE statements are required.
 *
 * @package CrisperCode\Database
 */
class SchemaManager
{
    /**
     * Whether to run in dry run mode (show SQL without executing).
     */
    private bool $dryRun = false;

    /**
     * Whether to suppress output messages.
     */
    private bool $quiet = false;

    /**
     * Creates a new SchemaManager instance.
     *
     * @param MeekroDB $db The database connection instance.
     */
    public function __construct(
        private MeekroDB $db
    ) {
    }

    /**
     * Sets the dry run mode.
     *
     * When enabled, the schema manager will output SQL statements without
     * executing them, allowing you to preview what changes would be made.
     *
     * @param bool $dryRun Whether to enable dry run mode.
     * @return self Returns the instance for method chaining.
     */
    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * Checks if dry run mode is enabled.
     *
     * @return bool True if dry run mode is enabled.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Sets the quiet mode.
     *
     * When enabled, the schema manager will suppress all output messages.
     *
     * @param bool $quiet Whether to enable quiet mode.
     * @return self Returns the instance for method chaining.
     */
    public function setQuiet(bool $quiet): self
    {
        $this->quiet = $quiet;
        return $this;
    }

    /**
     * Detects the database driver (mysql or sqlite).
     *
     * @return string The database driver name.
     */
    private function getDriver(): string
    {
        $pdo = $this->db->get();
        if ($pdo === null) {
            throw new \RuntimeException('Database connection not initialized');
        }
        return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Checks if the database is SQLite.
     *
     * @return bool True if using SQLite.
     */
    private function isSQLite(): bool
    {
        return $this->getDriver() === 'sqlite';
    }

    /**
     * Checks if the database is PostgreSQL.
     *
     * @return bool True if using PostgreSQL.
     */
    private function isPostgreSQL(): bool
    {
        return $this->getDriver() === 'pgsql';
    }

    /**
     * Checks if the database is MySQL/MariaDB.
     *
     * @return bool True if using MySQL/MariaDB.
     */
    private function isMySQL(): bool
    {
        $driver = $this->getDriver();
        return $driver === 'mysql';
    }

    /**
     * Checks if a table exists in the database.
     *
     * @param string $tableName The table name to check.
     * @return bool True if table exists.
     */
    private function tableExists(string $tableName): bool
    {
        if ($this->isSQLite()) {
            $result = $this->db->queryFirstRow(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=%s",
                $tableName
            );
            return $result !== null;
        } elseif ($this->isPostgreSQL()) {
            $result = $this->db->queryFirstRow(
                "SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename=%s",
                $tableName
            );
            return $result !== null;
        } else {
            $tables = $this->db->queryFirstColumn("SHOW TABLES LIKE %s", $tableName);
            return !empty($tables);
        }
    }

    /**
     * Gets existing columns for a table.
     *
     * @param string $tableName The table name.
     * @return array<string, array<string, mixed>> Column definitions keyed by column name.
     */
    private function getExistingColumns(string $tableName): array
    {
        if ($this->isSQLite()) {
            $columns = $this->db->query("PRAGMA table_info(`$tableName`)");
            $result = [];
            foreach ($columns as $col) {
                $result[$col['name']] = $col;
            }
            return $result;
        } elseif ($this->isPostgreSQL()) {
            $columns = $this->db->query(
                "SELECT column_name, data_type, is_nullable, column_default 
                 FROM information_schema.columns 
                 WHERE table_schema='public' AND table_name=%s",
                $tableName
            );
            $result = [];
            foreach ($columns as $col) {
                $result[$col['column_name']] = $col;
            }
            return $result;
        } else {
            $columns = $this->db->query("SHOW COLUMNS FROM `$tableName`");
            $result = [];
            foreach ($columns as $row) {
                $result[$row['Field']] = $row;
            }
            return $result;
        }
    }

    /**
     * Gets existing indexes for a table.
     *
     * @param string $tableName The table name.
     * @return array<string, array<int, string>> Index definitions keyed by index name.
     */
    private function getExistingIndexes(string $tableName): array
    {
        if ($this->isSQLite()) {
            $indexesResult = $this->db->query("PRAGMA index_list(`$tableName`)");
            $indexes = [];
            foreach ($indexesResult as $row) {
                // Skip auto-created indexes for PRIMARY KEY
                if (str_starts_with($row['name'], 'sqlite_autoindex_')) {
                    continue;
                }
                $indexName = $row['name'];
                $indexInfo = $this->db->query("PRAGMA index_info(`$indexName`)");
                foreach ($indexInfo as $col) {
                    $indexes[$indexName][] = $col['name'];
                }
            }
            return $indexes;
        } elseif ($this->isPostgreSQL()) {
            $query = "
                SELECT i.relname AS index_name, a.attname AS column_name
                FROM pg_index ix
                JOIN pg_class t ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                WHERE t.relname = %s
                  AND t.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
                  AND NOT ix.indisprimary
                ORDER BY i.relname, a.attnum
            ";
            $indexesResult = $this->db->query($query, $tableName);
            $indexes = [];
            foreach ($indexesResult as $row) {
                $indexes[$row['index_name']][] = $row['column_name'];
            }
            return $indexes;
        } else {
            $existingIndexesResult = $this->db->query("SHOW INDEX FROM `$tableName`");
            $indexes = [];
            foreach ($existingIndexesResult as $row) {
                if ($row['Key_name'] === 'PRIMARY') {
                    continue;
                }
                $indexes[$row['Key_name']][] = $row['Column_name'];
            }
            return $indexes;
        }
    }

    /**
     * Synchronizes the database table for a given entity class.
     *
     * Reads Column and Index attributes from the entity class and its parents,
     * then creates the table if it doesn't exist, or updates it if it does.
     *
     * @param string $className The fully-qualified class name of the entity.
     *
     * @throws \Exception If the class does not exist or does not define a TABLE_NAME constant.
     *
     * @example
     * $manager = new SchemaManager($db);
     * $manager->syncTable(CrisperCode\Entity\Ticker::class);
     */
    public function syncTable(string $className): void
    {
        if (!class_exists($className)) {
            throw new \Exception("Class $className does not exist");
        }

        $ref = new ReflectionClass($className);
        if (!$ref->isSubclassOf(EntityBase::class)) {
            return;
        }

        // Use the static getTableName() method to get the table name
        try {
            $tableName = $className::getTableName();
        } catch (\LogicException $e) {
            throw new \Exception("Entity $className does not define TABLE_NAME constant", $e->getCode(), $e);
        }

        $columns = $this->getColumns($ref);
        $indexes = $this->getIndexes($ref, $tableName);

        if (!$this->tableExists($tableName)) {
            $this->createTable($tableName, $columns, $indexes);
        } else {
            $this->updateTable($tableName, $columns, $indexes);
        }
    }

    /**
     * Collects column definitions from the entity class and all parent classes.
     *
     * @param ReflectionClass<EntityBase> $ref The reflection class of the entity.
     * @return array<string, array<string, mixed>> Column definitions keyed by column name.
     */
    private function getColumns(ReflectionClass $ref): array
    {
        $columns = [];

        // Traverse the class hierarchy to include parent class properties
        $currentRef = $ref;
        while ($currentRef !== false) {
            foreach ($currentRef->getProperties() as $property) {
                // Only process properties declared in the current class
                if ($property->getDeclaringClass()->getName() !== $currentRef->getName()) {
                    continue;
                }

                $attributes = $property->getAttributes(Column::class);
                if (empty($attributes)) {
                    continue;
                }

                $colAttr = $attributes[0]->newInstance();
                /** @var Column $colAttr */

                $colName = $colAttr->name !== '' ? $colAttr->name : $this->camelToSnake($property->getName());

                // Skip if we already have this column (child class takes precedence)
                if (isset($columns[$colName])) {
                    continue;
                }

                $definition = [
                    'name' => $colName,
                    'type' => $colAttr->type,
                    'length' => $colAttr->length,
                    'precision' => $colAttr->precision,
                    'scale' => $colAttr->scale,
                    'nullable' => $colAttr->nullable,
                    'autoIncrement' => $colAttr->autoIncrement,
                    'primaryKey' => $colAttr->primaryKey,
                    'default' => $colAttr->default,
                ];

                $columns[$colName] = $definition;
            }

            $currentRef = $currentRef->getParentClass();
        }

        return $columns;
    }

    /**
     * Collects index definitions from the entity class.
     *
     * @param ReflectionClass<EntityBase> $ref The reflection class of the entity.
     * @param string $tableName The table name for auto-generating index names.
     * @return array<int, array<string, mixed>> Index definitions.
     */
    private function getIndexes(ReflectionClass $ref, string $tableName): array
    {
        $indexes = [];
        $attributes = $ref->getAttributes(Index::class);
        foreach ($attributes as $attr) {
            $indexAttr = $attr->newInstance();
            /** @var Index $indexAttr */
            $columns = (array) $indexAttr->columns;

            // Auto-generate index name if not provided
            $indexName = $indexAttr->name;
            if ($indexName === '' || trim($indexName) === '') {
                $indexName = 'idx_' . $tableName . '_' . implode('_', $columns);
            }

            $indexes[] = [
                'name' => $indexName,
                'columns' => $columns,
                'unique' => $indexAttr->unique,
            ];
        }
        return $indexes;
    }

    /**
     * Creates a new database table with the specified columns and indexes.
     *
     * @param string $tableName The name of the table to create.
     * @param array<string, array<string, mixed>> $columns Column definitions.
     * @param array<int, array<string, mixed>> $indexes Index definitions.
     */
    private function createTable(string $tableName, array $columns, array $indexes): void
    {
        $lines = [];
        $pk = null;
        $pkHasAutoIncrement = false;
        foreach ($columns as $col) {
            $lines[] = $this->buildColumnDefinition($col);
            if ($col['primaryKey']) {
                $pk = $col['name'];
                $pkHasAutoIncrement = $col['autoIncrement'];
            }
        }
        // For SQLite with AUTOINCREMENT, PRIMARY KEY is already in column definition
        // For PostgreSQL with SERIAL, PRIMARY KEY is already in column definition
        // For MySQL, or SQLite without AUTOINCREMENT, add separate PRIMARY KEY constraint
        if ($pk !== null && !($this->isSQLite() && $pkHasAutoIncrement) && !($this->isPostgreSQL() && $pkHasAutoIncrement)) {
            $lines[] = "PRIMARY KEY (`$pk`)";
        }

        // For MySQL, add indexes inline. For SQLite and PostgreSQL, add them separately after table creation.
        if ($this->isMySQL()) {
            foreach ($indexes as $idx) {
                $cols = implode('`, `', $idx['columns']);
                $type = $idx['unique'] ? 'UNIQUE KEY' : 'KEY';
                $indexName = $idx['name'];
                $lines[] = "$type `$indexName` (`$cols`)";
            }
        }

        $sql = "CREATE TABLE `$tableName` (" . implode(", ", $lines) . ")";

        // Add MySQL-specific table options
        if ($this->isMySQL()) {
            $sql .= " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        if ($this->dryRun) {
            if (!$this->quiet) {
                echo "[DRY RUN] Would create table $tableName:\n";
                echo "  $sql;\n";
            }
        } else {
            if (!$this->quiet) {
                echo "Creating table $tableName...\n";
            }
            $this->db->query($sql);
        }

        // For SQLite and PostgreSQL, create indexes separately
        if ($this->isSQLite() || $this->isPostgreSQL()) {
            foreach ($indexes as $idx) {
                $cols = implode('`, `', $idx['columns']);
                $type = $idx['unique'] ? 'UNIQUE INDEX' : 'INDEX';
                $indexName = $idx['name'];
                $indexSql = "CREATE $type `$indexName` ON `$tableName` (`$cols`)";

                if ($this->dryRun) {
                    if (!$this->quiet) {
                        echo "[DRY RUN] Would create index $indexName:\n";
                        echo "  $indexSql;\n";
                    }
                } else {
                    if (!$this->quiet) {
                        echo "Creating index $indexName on $tableName...\n";
                    }
                    $this->db->query($indexSql);
                }
            }
        }
    }

    /**
     * Updates an existing database table to add missing columns and indexes.
     *
     * Note: Modifying existing columns is not supported. Only new columns and
     * indexes are added. To modify existing columns, use manual ALTER TABLE.
     *
     * @param string $tableName The name of the table to update.
     * @param array<string, array<string, mixed>> $columns Column definitions.
     * @param array<int, array<string, mixed>> $indexes Index definitions.
     */
    private function updateTable(string $tableName, array $columns, array $indexes): void
    {
        $existingColMap = $this->getExistingColumns($tableName);

        // Add new columns (modification of existing columns is not supported)
        foreach ($columns as $colName => $def) {
            $colDef = $this->buildColumnDefinition($def);
            if (!isset($existingColMap[$colName])) {
                $sql = "ALTER TABLE `$tableName` ADD COLUMN $colDef";
                if ($this->dryRun) {
                    if (!$this->quiet) {
                        echo "[DRY RUN] Would add column $colName to $tableName:\n";
                        echo "  $sql;\n";
                    }
                } else {
                    if (!$this->quiet) {
                        echo "Adding column $colName to $tableName...\n";
                    }
                    $this->db->query($sql);
                }
            }
        }

        // Manage Indexes
        $existingIndexes = $this->getExistingIndexes($tableName);

        foreach ($indexes as $idx) {
            $idxName = $idx['name'];
            $cols = implode('`, `', $idx['columns']);
            $type = $idx['unique'] ? 'UNIQUE INDEX' : 'INDEX';

            if (!isset($existingIndexes[$idxName])) {
                $sql = "CREATE $type `$idxName` ON `$tableName` (`$cols`)";
                if ($this->dryRun) {
                    if (!$this->quiet) {
                        echo "[DRY RUN] Would add index $idxName to $tableName:\n";
                        echo "  $sql;\n";
                    }
                } else {
                    if (!$this->quiet) {
                        echo "Adding index $idxName to $tableName...\n";
                    }
                    $this->db->query($sql);
                }
            } else {
                // Check if columns match with existing index
                $existingCols = $existingIndexes[$idxName];
                $definedCols = $idx['columns'];

                // Compare column arrays (order matters for indexes)
                if ($existingCols !== $definedCols && !$this->quiet) {
                    echo "Warning: Index `$idxName` on table `$tableName` exists but columns differ.\n";
                    echo "  Expected: " . implode(', ', $definedCols) . "\n";
                    echo "  Current: " . implode(', ', $existingCols) . "\n";
                    echo "  Manual ALTER TABLE required to modify index.\n";
                }
            }
        }
    }

    /**
     * Builds the SQL column definition string for a column.
     *
     * @param array<string, mixed> $def The column definition array.
     * @return string The SQL column definition.
     */
    private function buildColumnDefinition(array $def): string
    {
        $type = strtoupper((string) $def['type']);

        // Handle auto-increment columns with driver-specific types
        if ($def['autoIncrement'] && $def['primaryKey']) {
            if ($this->isSQLite()) {
                // SQLite requires INTEGER (not INT) for AUTOINCREMENT columns
                $type = 'INTEGER';
            } elseif ($this->isPostgreSQL()) {
                // PostgreSQL uses SERIAL/BIGSERIAL for auto-increment
                $type = match ($type) {
                    'BIGINT' => 'BIGSERIAL',
                    default => 'SERIAL',
                };
            }
        }

        // Handle ENUM type (not supported by SQLite and PostgreSQL)
        if ($type === 'ENUM') {
            if ($this->isSQLite()) {
                $type = 'TEXT';
            } elseif ($this->isPostgreSQL()) {
                // PostgreSQL could use custom ENUM types, but TEXT is simpler for portability
                $type = 'TEXT';
            }
        }

        // Handle type-specific formatting
        if ($type === 'DECIMAL' || $type === 'NUMERIC') {
            // Handle DECIMAL/NUMERIC with precision and scale
            $precision = $def['precision'] ?? $def['length'] ?? 10;
            $scale = $def['scale'] ?? 0;
            $type .= "($precision,$scale)";
        } elseif ($type === 'ENUM' && $def['length'] && $this->isMySQL()) {
            // Handle ENUM with values string (MySQL only)
            $type .= '(' . $def['length'] . ')';
        } elseif (in_array($type, ['VARCHAR', 'CHAR', 'VARBINARY', 'BINARY'], true) && $def['length']) {
            // Handle VARCHAR, CHAR, VARBINARY, and BINARY with length
            $type .= '(' . $def['length'] . ')';
        } elseif (in_array($type, ['INT', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT'], true) && $def['length'] && $this->isMySQL()) {
            // Handle integer types with display width (MySQL only, not used in PostgreSQL/SQLite)
            $type .= '(' . $def['length'] . ')';
        }

        $sql = '`' . $def['name'] . '` ' . $type;

        // Add NOT NULL constraint
        // For PostgreSQL with PRIMARY KEY, NOT NULL is implied
        // For SQLite with PRIMARY KEY, we explicitly add NOT NULL for clarity even though it's implied
        if (!$def['nullable']) {
            if ($this->isPostgreSQL() && $def['primaryKey']) {
                // PostgreSQL: NOT NULL is implied with PRIMARY KEY, skip it
            } else {
                $sql .= " NOT NULL";
            }
        }

        // Handle PRIMARY KEY constraint
        // SQLite requires PRIMARY KEY in column definition when using AUTOINCREMENT
        // PostgreSQL requires PRIMARY KEY in column definition when using SERIAL
        if ($def['autoIncrement'] && $def['primaryKey'] && ($this->isSQLite() || $this->isPostgreSQL())) {
            $sql .= " PRIMARY KEY";
        }

        // Handle default values
        if ($def['default'] !== null) {
            $default = $def['default'];
            if (is_string($default)) {
                // Use PDO's quote method for safe SQL escaping
                $pdo = $this->db->get();
                $default = $pdo !== null ? $pdo->quote($default) : "'" . addslashes($default) . "'";
            }
            $sql .= " DEFAULT $default";
        }

        // Handle AUTO_INCREMENT (MySQL only, SQLite uses AUTOINCREMENT with PRIMARY KEY above)
        // PostgreSQL uses SERIAL which handles auto-increment automatically
        if ($def['autoIncrement'] && $this->isMySQL()) {
            $sql .= " AUTO_INCREMENT";
        } elseif ($def['autoIncrement'] && $this->isSQLite() && $def['primaryKey']) {
            $sql .= " AUTOINCREMENT";
        }

        return $sql;
    }

    /**
     * Converts a camelCase string to snake_case.
     *
     * @param string $input The camelCase string.
     * @return string The snake_case string.
     */
    private function camelToSnake(string $input): string
    {
        $result = preg_replace('/(?<!^)[A-Z]/', '_$0', $input);
        if ($result === null) {
            return strtolower($input);
        }
        return strtolower($result);
    }
}
