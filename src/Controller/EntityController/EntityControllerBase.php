<?php

declare(strict_types=1);

namespace CrisperCode\Controller\EntityController;

use CrisperCode\Attribute\EntityControllerAttribute;
use CrisperCode\EntityManager\EntityManagerInterface;
use CrisperCode\Service\FlashMessageService;
use DI\Container;
use Psr\Http\Message\ServerRequestInterface as Request;
use ReflectionClass;

abstract class EntityControllerBase
{
    protected Container $container;
    /** @var array<string, EntityManagerInterface> */
    protected array $entityManagers = [];
    protected FlashMessageService $flashMessages;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->flashMessages = $container->get(FlashMessageService::class);
    }

    /**
     * Retrieves common variables used across most templates.
     *
     * Includes current path, date, and performance metrics.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return array{
     *     current_path: string,
     *     date: string,
     *     flash_messages: array<int, array{type: string, message: string, dismissible: bool, icon: string}>
     * }
     */
    protected function getCommonVariables(Request $request): array
    {
        return [
            'current_path' => $request->getUri()->getPath(),
            'date' => date('Y-m-d H:i:s', (int) ($_SERVER['REQUEST_TIME'] ?? time())),
            'flash_messages' => $this->flashMessages->getAndClear(),
        ];
    }

    /**
     * Gets the configuration from the EntityControllerAttribute.
     *
     * @return EntityControllerAttribute|null The attribute configuration or null if not found.
     */
    protected function getControllerAttribute(): ?EntityControllerAttribute
    {
        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(EntityControllerAttribute::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Gets the entity manager class name.
     *
     * First tries to get it from the EntityControllerAttribute,
     * falls back to the abstract method for backward compatibility.
     *
     * @return string The entity manager class name.
     */
    protected function getEntityManagerClass(): string
    {
        $attribute = $this->getControllerAttribute();
        if ($attribute instanceof EntityControllerAttribute) {
            return $attribute->entityManagerClass;
        }

        // This method should be overridden by child classes if they don't use the attribute
        throw new \LogicException(
            sprintf(
                // @phpcs:ignore
                '%s must either be decorated with #[EntityControllerAttribute(...)] or override getEntityManagerClass()',
                static::class
            )
        );
    }

    protected function getEntityManager(string $entityManagerClass = ''): EntityManagerInterface
    {
        if ($entityManagerClass === '') {
            $entityManagerClass = $this->getEntityManagerClass();
        }

        if (!isset($this->entityManagers[$entityManagerClass])) {
            $this->entityManagers[$entityManagerClass] = $this->container->get($entityManagerClass);
        }
        return $this->entityManagers[$entityManagerClass];
    }
}
