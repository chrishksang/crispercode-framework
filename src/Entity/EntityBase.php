<?php

namespace CrisperCode\Entity;

use MeekroDB;
use CrisperCode\Attribute\Column;

/**
 * Base class for all database-backed entities.
 *
 * This class provides the fundamental infrastructure for ORM-like behavior,
 * including a database connection and a standard save method that handles
 * both insertion and updates based on the presence of an ID.
 *
 * @package CrisperCode\Entity
 */
class EntityBase implements EntityInterface
{
    /**
     * The unique identifier for the entity (Primary Key).
     */
    #[Column(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    /**
     * The database connection instance.
     */
    protected MeekroDB $db;

    protected array $rawData = [];

    public function get(string $key): mixed
    {
        return $this->rawData[$key] ?? null;
    }

    /**
     * Gets the table name for this entity class.
     *
     * This method uses late static binding to return the TABLE_NAME constant
     * from the actual entity class, enabling static access to table names
     * without requiring instantiation.
     *
     * @return string The table name for this entity.
     * @throws \LogicException If the entity class does not define TABLE_NAME constant.
     *
     * @example
     * $tableName = Backtest::getTableName(); // Returns 'backtests'
     */
    public static function getTableName(): string
    {
        $constantName = static::class . '::TABLE_NAME';
        if (!defined($constantName)) {
            throw new \LogicException(sprintf('Entity class %s must define a TABLE_NAME constant', static::class));
        }
        return constant($constantName);
    }

    /**
     * Cache for reflection metadata keyed by class name.
     * Stores property-to-column mappings to avoid repeated reflection operations.
     *
     * @var array<string, array{save: list<array{property: string, column: string}>, load: array<string, string>}>
     */
    private static array $reflectionCache = [];

    /**
     * EntityBase constructor.
     *
     * Injects the database connection required for persistence operations.
     *
     * @param MeekroDB $db The database connection instance.
     *
     * @example
     * $db = new MeekroDB(...);
     * $entity = new ConcreteEntity($db);
     */
    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
    }

    /**
     * Persists the entity's current state to the database.
     *
     * If the entity already has an ID, it performs an UPDATE operation.
     * If the entity does not have an ID, it performs an INSERT operation and sets the ID.
     *
     * @return int The ID of the saved entity.
     *
     * @throws \MeekroDBException If the database operation fails (e.g., connection error, constraint violation).
     *
     * @example
     * try {
     *     $entity->name = "New Name";
     *     $id = $entity->save();
     *     echo "Entity saved with ID: $id";
     * } catch (\MeekroDBException $e) {
     *     error_log("Save failed: " . $e->getMessage());
     * }
     */
    public function save(): int
    {
        $values = $this->values();
        $tableName = static::getTableName();
        if (isset($this->id) && $this->id !== 0) {
            $this->db->update($tableName, $values, "id=%i", $this->id);
        } else {
            $this->db->insert($tableName, $values);
            $this->id = $this->db->insertId();
        }
        return $this->id;
    }

    /**
     * Returns the array of values to be saved to the database.
     *
     * This method automatically maps object properties to database columns using
     * the #[Column] attributes. Properties with a #[Column] attribute are included,
     * with the column name taken from the attribute's name parameter (if provided)
     * or converted from the property name to snake_case.
     *
     * @return array<string, mixed> Associative array where keys are column names and values are the data to save.
     */
    protected function values(): array
    {
        $metadata = $this->getReflectionMetadata();
        $values = [];

        foreach ($metadata['save'] as $mapping) {
            $property = new \ReflectionProperty($this, $mapping['property']);

            // Only include if the property is initialized
            if (!$property->isInitialized($this)) {
                continue;
            }

            $values[$mapping['column']] = $property->getValue($this);
        }

        return $values;
    }

    /**
     * Hydrates the entity from an associative array of database values.
     *
     * This method automatically maps database columns to object properties using
     * the #[Column] attributes. Column names are matched to properties based on
     * the attribute's name parameter or by converting the property name to snake_case.
     *
     * @param array<string, mixed> $values The raw database row.
     *
     * @return static The hydrated entity instance.
     */
    public function loadFromValues(array $values): static
    {
        $this->rawData = $values;
        $metadata = $this->getReflectionMetadata();

        foreach ($values as $columnName => $value) {
            if (isset($metadata['load'][$columnName])) {
                $property = new \ReflectionProperty($this, $metadata['load'][$columnName]);
                $property->setValue($this, $value);
            }
        }

        return $this;
    }

    /**
     * Gets or builds the reflection metadata for this entity class.
     *
     * Metadata is cached per class to avoid repeated reflection operations.
     * The metadata includes mappings for both saving (property to column) and
     * loading (column to property) operations.
     *
     * @return array{save: list<array{property: string, column: string}>, load: array<string, string>}
     */
    private function getReflectionMetadata(): array
    {
        $className = static::class;

        if (isset(self::$reflectionCache[$className])) {
            return self::$reflectionCache[$className];
        }

        $reflection = new \ReflectionClass($this);
        $saveMetadata = [];
        $loadMetadata = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Column::class);

            if (empty($attributes)) {
                continue;
            }

            $columnAttribute = $attributes[0]->newInstance();
            $propertyName = $property->getName();

            // Get the column name from the attribute or convert property name to snake_case
            $columnName = $columnAttribute->name;
            if ($columnName === '') {
                $columnName = $this->toSnakeCase($propertyName);
            }

            // Skip primary key with auto-increment for save operations
            if (!($columnAttribute->primaryKey && $columnAttribute->autoIncrement)) {
                $saveMetadata[] = [
                    'property' => $propertyName,
                    'column' => $columnName,
                ];
            }

            // All columns can be used for load operations
            $loadMetadata[$columnName] = $propertyName;
        }

        self::$reflectionCache[$className] = [
            'save' => $saveMetadata,
            'load' => $loadMetadata,
        ];

        return self::$reflectionCache[$className];
    }

    /**
     * Converts a camelCase or PascalCase string to snake_case.
     *
     * Uses a two-step approach to handle consecutive uppercase letters correctly:
     * 1. Insert underscore between lowercase and uppercase letters
     * 2. Insert underscore between uppercase letters followed by lowercase
     *
     * @param string $input The input string to convert.
     * @return string The snake_case version of the input string.
     */
    private function toSnakeCase(string $input): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
        $result = preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', $result);
        return strtolower($result);
    }
}
