<?php

declare(strict_types=1);

namespace CrisperCode\Attribute;

use Attribute;

/**
 * Attribute to define configuration for entity managers.
 *
 * This attribute centralizes entity manager configuration including the entity class
 * and allowed sort fields for loadMultiple queries.
 *
 * @example
 * #[EntityManagerAttribute(
 *     entityClass: File::class,
 *     allowedSortFields: ['name', 'type', 'created', 'updated']
 * )]
 * class FileEntityManager extends EntityManagerBase {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class EntityManagerAttribute
{
    /**
     * @param class-string $entityClass The fully qualified class name of the entity this manager handles.
     * @param array<string> $allowedSortFields List of fields that can be used for sorting in loadMultiple queries.
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $allowedSortFields = []
    ) {
    }
}
