<?php

declare(strict_types=1);

namespace CrisperCode\Console\EntityProvider;

use CrisperCode\Entity\EntityBase;

/**
 * Interface for classes that provide entity class names for schema sync.
 *
 * Implement this interface to register entities with the schema:sync command.
 * Both framework and application code can provide entity providers.
 *
 * @package CrisperCode\Console\EntityProvider
 */
interface EntityProviderInterface
{
    /**
     * Returns an array of fully-qualified entity class names to sync.
     *
     * Order matters - entities with foreign key dependencies should come
     * after the entities they depend on.
     *
     * @return array<class-string<EntityBase>> List of entity class names extending EntityBase
     */
    public function getEntities(): array;
}
