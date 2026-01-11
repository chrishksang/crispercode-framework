<?php

declare(strict_types=1);

namespace CrisperCode\Cache;

/**
 * Cache service with pluggable storage backend.
 *
 * Provides a simple interface for caching with support for different
 * storage backends (database, Redis, file system, etc.).
 *
 * @package CrisperCode\Cache
 */
class Cache
{
    /**
     * Cache backend instance.
     */
    protected CacheBackendInterface $backend;

    /**
     * Cache constructor.
     *
     * @param CacheBackendInterface $backend Cache storage backend.
     */
    public function __construct(CacheBackendInterface $backend)
    {
        $this->backend = $backend;
    }

    /**
     * Retrieves a value from the cache.
     *
     * Returns null if the key does not exist or has expired.
     *
     * @param string $name The cache key.
     *
     * @return string|null The cached value or null.
     *
     * @example
     * $value = $cache->get('user_profile_123');
     * if ($value) {
     *     $profile = json_decode($value, true);
     * }
     */
    public function get(string $name): ?string
    {
        return $this->backend->get($name);
    }

    /**
     * Stores a value in the cache.
     *
     * @param string $name The cache key.
     * @param string $value The value to store.
     * @param int $expires_in Expiration time in seconds (default: 86400 / 1 day).
     *
     * @example
     * $cache->set('api_response', $json, 3600);
     */
    public function set(string $name, string $value, int $expires_in = 86400): void
    {
        $this->backend->set($name, $value, $expires_in);
    }

    /**
     * Removes items from the cache.
     *
     * Supports wildcard matching (backend-dependent).
     *
     * @param string $name The cache key pattern (e.g., 'user_123' or 'user_%').
     *
     * @example
     * $cache->invalidate('session_%'); // Clear all session keys
     */
    public function invalidate(string $name): void
    {
        $this->backend->invalidate($name);
    }
}
