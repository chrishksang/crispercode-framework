<?php

declare(strict_types=1);

namespace CrisperCode\Cache;

/**
 * Database-backed cache storage implementation.
 *
 * Stores cache entries in a database table using MeekroDB.
 *
 * @package CrisperCode\Cache
 */
class DatabaseCacheBackend implements CacheBackendInterface
{
    /**
     * Database connection instance.
     */
    protected \MeekroDB $db;

    /**
     * Name of the cache table.
     */
    protected string $table = 'cache';

    /**
     * DatabaseCacheBackend constructor.
     *
     * @param \MeekroDB $db Database connection.
     */
    public function __construct(\MeekroDB $db)
    {
        $this->db = $db;
    }

    /**
     * {@inheritdoc}
     *
     * Note: Returns null for empty strings to match the original implementation.
     * MeekroDB's queryFirstField returns the value, null if not found, or false on error.
     */
    public function get(string $name): ?string
    {
        $value = $this->db->queryFirstField(
            'SELECT value FROM %l WHERE `name` = %s AND expires > %d',
            $this->table,
            $name,
            time()
        );
        // Return null for empty strings, null results, and false errors
        // This maintains backwards compatibility with the original implementation
        return (in_array($value, [false, '', null], true)) ? null : $value;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $name, string $value, int $expires_in): void
    {
        $this->db->insertUpdate($this->table, [
            'name' => $name,
            'value' => $value,
            'expires' => time() + $expires_in,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate(string $name): void
    {
        $this->db->query('DELETE FROM %l WHERE `name` LIKE %ss', $this->table, $name);
    }
}
