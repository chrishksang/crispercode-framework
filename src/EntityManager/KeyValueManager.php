<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\KeyValue;

/**
 * Manager for KeyValue entities.
 *
 * Provides typed access to key-value storage with automatic serialization/deserialization.
 *
 * @extends EntityManagerBase<KeyValue>
 * @package CrisperCode\EntityManager
 */
#[EntityManagerAttribute(
    entityClass: KeyValue::class,
    allowedSortFields: ['key_name', 'type']
)]
class KeyValueManager extends EntityManagerBase implements EntityManagerInterface
{
    /**
     * Gets a value by key, with optional default if key doesn't exist.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default The default value to return if key doesn't exist.
     * @return mixed The deserialized value, or default if not found.
     * @throws \MeekroDBException If the query fails.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $tableName = $this->getTableName();

        $row = $this->db->queryFirstRow(
            "SELECT * FROM {$tableName} WHERE key_name = %s",
            $key
        );

        if ($row === null) {
            return $default;
        }

        return $this->castValue($row['value'], $row['type']);
    }

    /**
     * Sets a value for a key, with automatic type inference.
     *
     * @param string $key The key to set.
     * @param mixed $value The value to store.

     * @throws \MeekroDBException If the query fails.
     * @throws \JsonException If JSON encoding fails.
     */
    public function set(string $key, mixed $value): void
    {
        $tableName = $this->getTableName();

        // Infer type and serialize value
        $type = $this->inferType($value);
        $serialized = $this->serializeValue($value, $type);

        // Check if key already exists
        $existing = $this->db->queryFirstRow(
            "SELECT id FROM {$tableName} WHERE key_name = %s",
            $key
        );

        if ($existing !== null) {
            // Update existing
            $this->db->update(
                $tableName,
                [
                    'value' => $serialized,
                    'type' => $type,
                ],
                'id=%i',
                $existing['id']
            );
        } else {
            // Insert new
            $this->db->insert(
                $tableName,
                [
                    'key_name' => $key,
                    'value' => $serialized,
                    'type' => $type,
                ]
            );
        }
    }

    /**
     * Deletes a key-value pair by key name.
     *
     * @param string $key The key to delete.

     * @throws \MeekroDBException If the query fails.
     */
    public function deleteByKey(string $key): void
    {
        $tableName = $this->getTableName();
        $this->db->delete($tableName, 'key_name=%s', $key);
    }

    /**
     * Checks if a key exists by key name.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists, false otherwise.
     * @throws \MeekroDBException If the query fails.
     */
    public function existsByKey(string $key): bool
    {
        $tableName = $this->getTableName();

        $row = $this->db->queryFirstRow(
            "SELECT id FROM {$tableName} WHERE key_name = %s",
            $key
        );

        return $row !== null;
    }

    /**
     * Casts a serialized value back to its original type.
     *
     * This method deserializes values based on their stored type metadata.
     * For array/json types, it uses JSON_THROW_ON_ERROR to fail fast on corrupted data
     * rather than silently returning null, which helps detect data integrity issues.
     *
     * @param string|null $serialized The serialized value from the database.
     * @param string $type The type to cast to.
     * @return mixed The deserialized value.
     * @throws \JsonException If JSON decoding fails due to malformed data.
     */
    private function castValue(?string $serialized, string $type): mixed
    {
        if ($serialized === null) {
            return null;
        }

        return match ($type) {
            'string' => $serialized,
            'int' => (int) $serialized,
            'float' => (float) $serialized,
            'bool' => $serialized === '1',
            'array', 'json' => json_decode($serialized, true, 512, JSON_THROW_ON_ERROR),
            'null' => null,
            default => $serialized,
        };
    }

    /**
     * Infers the type of a value.
     *
     * @param mixed $value The value to infer the type from.
     * @return string The inferred type name.
     */
    private function inferType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }

    /**
     * Serializes a value to a string for storage.
     *
     * @param mixed $value The value to serialize.
     * @param string $type The type of the value.
     * @return string|null The serialized value.
     * @throws \JsonException If JSON encoding fails.
     */
    private function serializeValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            'null' => null,
            'bool' => $value ? '1' : '0',
            'array', 'json' => json_encode($value, JSON_THROW_ON_ERROR),
            'int', 'float' => (string) $value,
            default => (string) $value,
        };
    }
}
