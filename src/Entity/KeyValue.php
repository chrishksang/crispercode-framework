<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;

/**
 * KeyValue entity for storing arbitrary key-value configuration and metadata.
 *
 * This entity supports storing values of different types (string, int, float, bool, array, json, null)
 * with automatic serialization and deserialization based on the stored type.
 *
 * @package CrisperCode\Entity
 */
#[Index(columns: ['key_name'], name: 'idx_key_name', unique: true)]
class KeyValue extends EntityBase
{
    public const TABLE_NAME = 'key_values';

    /**
     * The unique key identifier for this key-value pair.
     * Using 'key_name' to avoid MySQL reserved keyword 'key'.
     */
    #[Column(type: 'VARCHAR', name: 'key_name', length: 255)]
    public string $keyName;

    /**
     * The serialized value stored as a string.
     * All values are converted to strings for storage.
     */
    #[Column(type: 'LONGTEXT', nullable: true)]
    public ?string $value;

    /**
     * The type of the original value before serialization.
     * Used to properly deserialize the value when retrieved.
     * Valid values: string, int, float, bool, array, json, null
     */
    #[Column(type: 'VARCHAR', length: 50, default: 'string')]
    public string $type;
}
