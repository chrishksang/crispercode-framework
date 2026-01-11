<?php

declare(strict_types=1);

namespace CrisperCode\Attribute;

use Attribute;

/**
 * Attribute to define database indexes on entity classes.
 *
 * This attribute allows entities to declaratively specify their database indexes,
 * including multi-column indexes and unique constraints.
 *
 * @example
 * #[Index(columns: ['ticker_id', 'datetime'], name: 'idx_ticker_datetime', unique: true)]
 * class Price extends EntityBase { ... }
 *
 * #[Index(columns: 'symbol', name: 'idx_symbol')]
 * class Ticker extends EntityBase { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Index
{
    /**
     * Creates a new Index attribute instance.
     *
     * @param array<string>|string $columns Column name(s) to include in the index.
     * @param string $name Optional index name. If empty, one will be auto-generated.
     * @param bool $unique Whether this is a unique index.
     */
    public function __construct(
        public array|string $columns,
        public string $name = '',
        public bool $unique = false,
    ) {
    }
}
