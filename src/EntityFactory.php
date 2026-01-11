<?php

declare(strict_types=1);

namespace CrisperCode;

use CrisperCode\Entity\EntityInterface;
use MeekroDB;

/**
 * Factory for creating and hydrating Entity instances.
 *
 * This factory provides a convenient way to create entity instances
 * and populate them with data from external sources (e.g., API responses).
 * It works with the dependency injection container to properly inject
 * required dependencies like the database connection.
 *
 * @package CrisperCode
 */
class EntityFactory
{
    /**
     * @param MeekroDB $db The database connection instance.
     */
    public function __construct(
        private MeekroDB $db
    ) {
    }

    /**
     * Creates a new entity instance of the specified class.
     *
     * The entity is instantiated with the database connection injected.
     * If data is provided, the entity is hydrated with that data using
     * the entity's loadFromValues method.
     *
     * @template T of EntityInterface
     * @param class-string<T> $entityClass The fully qualified class name of the entity.
     * @param array<string, mixed> $data Optional associative array of data to populate the entity.
     * @return T The created and optionally hydrated entity instance.
     *
     * @example
     * // Create an empty Price entity
     * $price = $entityFactory->create(Price::class);
     *
     * @example
     * // Create and hydrate a Price entity from API data
     * $apiData = [
     *     'ticker_id' => 1,
     *     'datetime' => '2023-01-01 09:30:00',
     *     'open' => 100.0,
     *     'high' => 105.0,
     *     'low' => 99.0,
     *     'close' => 103.0,
     *     'volume' => 10000,
     *     'timeframe' => 'MINUTE_15',
     * ];
     * $price = $entityFactory->create(Price::class, $apiData);
     * $price->save();
     */
    public function create(string $entityClass, array $data = []): EntityInterface
    {
        $entity = new $entityClass($this->db);

        if ($data !== []) {
            $entity->loadFromValues($data);
        }

        return $entity;
    }

    /**
     * Finds an entity by its primary key ID.
     *
     * @template T of EntityInterface
     * @param class-string<T> $entityClass The fully qualified class name of the entity.
     * @param int $id The primary key ID.
     * @return T|null The found entity or null if not found.
     */
    public function findById(string $entityClass, int $id): ?EntityInterface
    {
        $tableName = $entityClass::getTableName();
        $row = $this->db->queryFirstRow("SELECT * FROM %b WHERE id = %i", $tableName, $id);

        if ($row === null) {
            return null;
        }

        return $this->create($entityClass, $row);
    }

    /**
     * Finds a single entity matching the given criteria.
     *
     * @template T of EntityInterface
     * @param class-string<T> $entityClass The fully qualified class name of the entity.
     * @param array<string, mixed> $criteria Column-value pairs to match.
     * @return T|null The found entity or null if not found.
     *
     * @example
     * $user = $entityFactory->findOneBy(User::class, ['email' => 'test@example.com']);
     */
    public function findOneBy(string $entityClass, array $criteria): ?EntityInterface
    {
        $tableName = $entityClass::getTableName();

        // Build WHERE clause
        $whereParts = [];
        $params = [$tableName];

        foreach ($criteria as $column => $value) {
            $whereParts[] = "%b = %s";
            $params[] = $column;
            $params[] = $value;
        }

        $whereClause = implode(' AND ', $whereParts);
        $query = "SELECT * FROM %b WHERE " . $whereClause . " LIMIT 1";

        $row = $this->db->queryFirstRow($query, ...$params);

        if ($row === null) {
            return null;
        }

        return $this->create($entityClass, $row);
    }
}
