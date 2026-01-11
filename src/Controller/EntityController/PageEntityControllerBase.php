<?php

namespace CrisperCode\Controller\EntityController;

use CrisperCode\Attribute\EntityControllerAttribute;
use CrisperCode\Entity\EntityInterface;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class PageEntityControllerBase extends EntityControllerBase implements PageControllerInterface
{
    /**
     * Twig template engine.
     */
    protected Twig $view;

    protected ?EntityInterface $entity;

    public function __construct(Container $container, Twig $view)
    {
        $this->view = $view;
        parent::__construct($container);
    }

    /**
     * Gets the configuration from the EntityControllerAttribute.
     *
     * @return EntityControllerAttribute The attribute configuration.
     *
     * @throws \LogicException If the attribute is not found on the class.
     */
    protected function getControllerAttribute(): EntityControllerAttribute
    {
        $attribute = parent::getControllerAttribute();

        if (!$attribute instanceof EntityControllerAttribute) {
            throw new \LogicException(
                sprintf(
                    '%s must be decorated with #[EntityControllerAttribute(...)]',
                    static::class
                )
            );
        }

        return $attribute;
    }

    public function getPageTemplate(): string
    {
        $pageTemplate = $this->getControllerAttribute()->pageTemplate;
        if ($pageTemplate === null) {
            throw new \LogicException(
                sprintf(
                    '%s EntityControllerAttribute must specify pageTemplate for page controllers',
                    static::class
                )
            );
        }
        return $pageTemplate;
    }



    protected function getEntityManagerClass(): string
    {
        return $this->getControllerAttribute()->entityManagerClass;
    }

    /**
     * Renders the details page for a specific special date.
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     *
     * @return Response Rendered details page or 404 if special date not found.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function page(Request $request, Response $response): Response
    {
        $entity = $this->getPageEntity($request);

        if (!$entity instanceof EntityInterface) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, $this->getPageTemplate(), array_merge([
            'title' => $this->getPageTitle($entity),
            'entity' => $entity,
        ], $this->getCommonVariables($request), $this->getAdditionalVariables($request)));
    }

    protected function getAdditionalVariables(Request $request): array
    {
        return [];
    }

    protected function getPageEntity(Request $request, bool $cache = true): ?EntityInterface
    {
        if ($cache === false || !isset($this->entity)) {
            $entity_id = (int) $request->getAttribute('id');
            $entity = $this->getEntityManager()->load($entity_id);
            $this->entity = $entity;
        }
        return $this->entity;
    }
}
