<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

/**
 * Interface for all entities.
 *
 * Provides the contract for entity operations.
 *
 * @package CrisperCode\Entity
 */
interface EntityInterface
{
    /**
     * Persists the entity's current state to the database.
     *
     * @return int The ID of the saved entity.
     */
    public function save(): int;

    /**
     * Gets the table name for this entity class.
     *
     * @return string The table name for this entity.
     */
    public static function getTableName(): string;

    /**
     * Loads the entity from the database using the provided values.
     *
     * @param array<string, mixed> $values The values to load into the entity.
     * @return static The loaded entity.
     */
    public function loadFromValues(array $values): static;
}
