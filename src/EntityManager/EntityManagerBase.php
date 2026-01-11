<?php

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\EntityInterface;
use CrisperCode\EntityFactory;
use MeekroDB;

/**
 * Base abstract class for Entity Managers.
 *
 * Provides the contract for loading entities from the database.
 *
 * @package CrisperCode\EntityManager
 * @template T of EntityInterface
 */
abstract class EntityManagerBase implements EntityManagerInterface
{
    /**
     * Database connection instance.
     */
    protected MeekroDB $db;

    /**
     * Entity factory instance.
     */
    public EntityFactory $entityFactory;

    /**
     * Allowed sort fields for loadMultiple queries.
     *
     * @var array<string>
     */
    protected array $allowedSortFields = [];

    /**
     * The fully qualified class name of the entity this manager handles.
     *
     * @var class-string<T>
     */
    protected string $entityClass;

    /**
     * EntityManagerBase constructor.
     *
     * @param MeekroDB $db Database connection.
     * @param EntityFactory $entityFactory Entity factory instance.
     */
    public function __construct(MeekroDB $db, EntityFactory $entityFactory)
    {
        $this->db = $db;
        $this->entityFactory = $entityFactory;

        // Read EntityManagerAttribute to configure entity class and allowed sort fields
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(EntityManagerAttribute::class);

        if (($attributes) === []) {
            throw new \LogicException(
                sprintf(
                    '%s must be decorated with #[EntityManagerAttribute(...)]',
                    static::class
                )
            );
        }

        $attribute = $attributes[0]->newInstance();
        $this->entityClass = $attribute->entityClass;
        $this->allowedSortFields = $attribute->allowedSortFields;
    }

    /**
     * Returns the fully qualified class name of the entity this manager handles.
     *
     * @return class-string<T> The entity class name.
     */
    protected function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /**
     * Hydrates an entity object from an associative array of database values.
     *
     * Uses the EntityFactory to create and populate entities with the #[Column] attributes.
     *
     * @param array<string, mixed> $values The raw database row.
     *
     * @return T The hydrated entity.
     */
    protected function loadFromValues(array $values): EntityInterface
    {
        $entityClass = $this->getEntityClass();
        return $this->entityFactory->create($entityClass, $values);
    }

    /**
     * Gets and validates the table name for the entity.
     *
     * @return string The validated table name.
     *
     * @throws \InvalidArgumentException If the table name is invalid.
     */
    protected function getTableName(): string
    {
        $entityClass = $this->getEntityClass();
        $tableName = $entityClass::getTableName();

        // Validate table name to prevent SQL injection
        if (!preg_match('/^[a-z_]+$/', $tableName)) {
            throw new \InvalidArgumentException("Invalid table name: {$tableName}");
        }

        return $tableName;
    }

    /**
     * Loads a single entity by its primary key ID.
     *
     * @param int $id The entity ID.
     *
     * @return T|null The loaded entity, or null if not found.
     *
     * @throws \MeekroDBException If the query fails.
     */
    public function load(int $id): ?EntityInterface
    {
        $tableName = $this->getTableName();

        $row = $this->db->queryFirstRow("SELECT * FROM {$tableName} WHERE id = %i", $id);
        if ($row === null) {
            return null;
        }
        return $this->loadFromValues($row);
    }

    /**
     * Loads multiple entities with optional filtering and sorting.
     *
     * @param array<string, mixed> $params Parameters for the query:
     *   - 'ids' (optional): Array of entity IDs to filter by
     *   - 'sort' (optional): Field name to sort by (must be in $allowedSortFields)
     *   - 'order' (optional): Sort order ('asc' or 'desc', defaults to 'asc')
     *   - 'limit' (optional): Maximum number of entities to return
     *   - 'offset' (optional): Number of entities to skip
     *
     * @return array<T> Array of loaded entities.
     *
     * @throws \MeekroDBException If the query fails.
     */
    public function loadMultiple(array $params = []): array
    {
        $ids = $params['ids'] ?? [];
        $sort = $params['sort'] ?? null;
        $order = strtoupper($params['order'] ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';
        $limit = $params['limit'] ?? null;
        $offset = $params['offset'] ?? null;

        $tableName = $this->getTableName();

        $orderClause = '';
        // Validate sort field pattern before checking allowedSortFields
        if ($sort !== null) {
            if (!preg_match('/^[a-z_]+$/', $sort)) {
                throw new \InvalidArgumentException("Invalid sort field: {$sort}");
            }
            if (in_array($sort, $this->allowedSortFields, true)) {
                $orderClause = " ORDER BY " . $sort . " " . $order;
            }
        }

        $limitClause = '';
        if ($limit !== null && $limit > 0) {
            $limitClause = " LIMIT " . (int) $limit;
            if ($offset !== null && $offset > 0) {
                $limitClause .= " OFFSET " . (int) $offset;
            }
        }

        if (empty($ids)) {
            $rows = $this->db->query("SELECT * FROM {$tableName}" . $orderClause . $limitClause);
        } else {
            $rows = $this->db->query("SELECT * FROM {$tableName} WHERE id IN %li" . $orderClause . $limitClause, $ids);
        }

        return array_map(function ($row) {
            return $this->loadFromValues($row);
        }, $rows);
    }

    /**
     * Counts the total number of entities in the table.
     *
     * @return int Total count of entities.
     *
     * @throws \MeekroDBException If the query fails.
     */
    public function count(): int
    {
        $tableName = $this->getTableName();
        return (int) $this->db->queryFirstField("SELECT COUNT(*) FROM {$tableName}");
    }

    /**
     * Deletes an entity by its primary key ID.
     *
     * @param int $id The entity ID.
     *
     *
     * @throws \MeekroDBException If the query fails.
     */
    public function delete(int $id): void
    {
        $tableName = $this->getTableName();
        $this->db->delete($tableName, 'id=%i', $id);
    }

    public function getEntityFactory(): EntityFactory
    {
        return $this->entityFactory;
    }
}
