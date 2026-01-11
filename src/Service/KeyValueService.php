<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use CrisperCode\EntityManager\KeyValueManager;

/**
 * Service layer for KeyValue storage with domain-specific helpers.
 *
 * This service provides convenience methods for common configuration and metadata operations.
 *
 * @package CrisperCode\Service
 */
class KeyValueService
{
    private KeyValueManager $manager;

    /**
     * KeyValueService constructor.
     *
     * @param KeyValueManager $manager The KeyValue entity manager.
     */
    public function __construct(KeyValueManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Gets a configuration value by key.
     *
     * @param string $key The configuration key.
     * @param mixed $default The default value if not found.
     * @return mixed The configuration value.
     * @throws \MeekroDBException If the query fails.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->manager->get("config.{$key}", $default);
    }

    /**
     * Sets a configuration value.
     *
     * @param string $key The configuration key.
     * @param mixed $value The value to set.

     * @throws \MeekroDBException If the query fails.
     * @throws \JsonException If JSON encoding fails.
     */
    public function setConfig(string $key, mixed $value): void
    {
        $this->manager->set("config.{$key}", $value);
    }

    /**
     * Gets a metadata value by key.
     *
     * @param string $key The metadata key.
     * @param mixed $default The default value if not found.
     * @return mixed The metadata value.
     * @throws \MeekroDBException If the query fails.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->manager->get("metadata.{$key}", $default);
    }

    /**
     * Sets a metadata value.
     *
     * @param string $key The metadata key.
     * @param mixed $value The value to set.

     * @throws \MeekroDBException If the query fails.
     * @throws \JsonException If JSON encoding fails.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $this->manager->set("metadata.{$key}", $value);
    }

    /**
     * Deletes a key-value pair directly without namespace prefixing.
     *
     * This method provides direct access to the underlying manager for cases
     * where you need to delete keys that don't follow the config.* or metadata.*
     * namespace pattern. Use with caution.
     *
     * @param string $key The key to delete.

     * @throws \MeekroDBException If the query fails.
     */
    public function delete(string $key): void
    {
        $this->manager->deleteByKey($key);
    }

    /**
     * Checks if a key exists directly without namespace prefixing.
     *
     * This method provides direct access to the underlying manager for cases
     * where you need to check keys that don't follow the config.* or metadata.*
     * namespace pattern. Use with caution.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists.
     * @throws \MeekroDBException If the query fails.
     */
    public function exists(string $key): bool
    {
        return $this->manager->existsByKey($key);
    }
}
