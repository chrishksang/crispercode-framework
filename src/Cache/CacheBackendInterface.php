<?php

declare(strict_types=1);

namespace CrisperCode\Cache;

/**
 * Interface for cache storage backends.
 *
 * Defines the contract for cache storage implementations.
 * Allows for different storage mechanisms (database, Redis, file system, etc.).
 *
 * @package CrisperCode\Cache
 */
interface CacheBackendInterface
{
    /**
     * Retrieves a value from the cache.
     *
     * Returns null if the key does not exist or has expired.
     *
     * @param string $name The cache key.
     *
     * @return string|null The cached value or null.
     */
    public function get(string $name): ?string;

    /**
     * Stores a value in the cache.
     *
     * @param string $name The cache key.
     * @param string $value The value to store.
     * @param int $expires_in Expiration time in seconds.
     */
    public function set(string $name, string $value, int $expires_in): void;

    /**
     * Removes items from the cache.
     *
     * @param string $name The cache key pattern.
     */
    public function invalidate(string $name): void;
}
