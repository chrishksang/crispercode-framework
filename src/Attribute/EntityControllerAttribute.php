<?php

declare(strict_types=1);

namespace CrisperCode\Attribute;

use Attribute;

/**
 * Attribute to define configuration for entity controllers.
 *
 * This attribute centralizes controller configuration including entity manager class
 * and optionally page template for page controllers.
 *
 * @example
 * // For page controllers:
 * #[EntityControllerAttribute(
 *     pageTemplate: 'pages/special_date.twig',
 *     entityManagerClass: SpecialDateEntityManager::class
 * )]
 * class SpecialDatePageEntityController extends PageEntityControllerBase {}
 *
 * // For non-page controllers:
 * #[EntityControllerAttribute(
 *     entityManagerClass: BacktestEntityManager::class
 * )]
 * class BacktestRunEntityController extends EntityControllerBase {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class EntityControllerAttribute
{
    public function __construct(
        public readonly string $entityManagerClass,
        public readonly ?string $pageTemplate = null,
        public readonly ?string $defaultSort = null
    ) {
    }
}
