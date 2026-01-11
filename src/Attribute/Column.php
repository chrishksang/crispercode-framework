<?php

declare(strict_types=1);

namespace CrisperCode\Attribute;

use Attribute;

/**
 * Attribute to define database column schema on entity properties.
 *
 * This attribute allows entities to declaratively specify their database column
 * definitions, including type, length, nullability, auto-increment, and default values.
 *
 * @example
 * #[Column(type: 'VARCHAR', length: 255)]
 * public string $name;
 *
 * #[Column(type: 'INT', primaryKey: true, autoIncrement: true)]
 * public int $id;
 *
 * #[Column(type: 'DECIMAL', precision: 10, scale: 2)]
 * public float $price;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    /**
     * Creates a new Column attribute instance.
     *
     * @param string $type The SQL column type (e.g., 'VARCHAR', 'INT', 'DECIMAL').
     * @param string $name Optional column name. If empty, property name is converted to snake_case.
     * @param int|null $length Optional length for VARCHAR/CHAR types, or precision for INT types.
     * @param int|null $precision Optional precision for DECIMAL type (total digits).
     * @param int|null $scale Optional scale for DECIMAL type (digits after decimal point).
     * @param bool $nullable Whether the column allows NULL values.
     * @param bool $autoIncrement Whether the column auto-increments.
     * @param bool $primaryKey Whether the column is a primary key.
     * @param mixed $default Optional default value for the column.
     */
    public function __construct(
        public string $type,
        public string $name = '',
        public string|int|null $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $nullable = false,
        public bool $autoIncrement = false,
        public bool $primaryKey = false,
        public mixed $default = null,
    ) {
    }
}
