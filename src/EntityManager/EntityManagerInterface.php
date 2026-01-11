<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Entity\EntityInterface;
use CrisperCode\EntityFactory;

/**
 * Interface for all entity managers.
 *
 * Provides the contract for entity manager operations.
 *
 * @package CrisperCode\EntityManager
 */
interface EntityManagerInterface
{
    /**
     * Loads a single entity by its primary key ID.
     *
     * @param int $id The entity ID.
     *
     * @return EntityInterface|null The loaded entity, or null if not found.
     */
    public function load(int $id): ?EntityInterface;

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
     * @return array<EntityInterface> Array of loaded entities.
     */
    public function loadMultiple(array $params = []): array;

    /**
     * Deletes an entity by its primary key ID.
     *
     * @param int $id The entity ID.
     */
    public function delete(int $id): void;

    /**
     * Counts the total number of entities in the table.
     *
     * @return int Total count of entities.
     */
    public function count(): int;

    /**
     * Gets the entity factory associated with this manager.
     *
     * @return EntityFactory The entity factory.
     */
    public function getEntityFactory(): EntityFactory;
}
