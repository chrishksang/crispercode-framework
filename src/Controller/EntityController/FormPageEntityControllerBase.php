<?php

namespace CrisperCode\Controller\EntityController;

use CrisperCode\Entity\EntityInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class FormPageEntityControllerBase extends PageEntityControllerBase
{
    abstract protected function getDeleteRedirectUrl(?string $entity_id): string;

    abstract protected function getFormAction(?EntityInterface $entity): string;

    abstract public function submit(Request $request, Response $response): Response;

    /**
     * Renders the special date form (create or edit).
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     *
     * @return Response Rendered form page or 404 if special date not found.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function form(Request $request, Response $response): Response
    {
        $entity_id = $request->getAttribute('id');
        $entity = $entity_id ? $this->getEntityManager()->load($entity_id) : null;

        if ($entity_id && !$entity instanceof EntityInterface) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, $this->getPageTemplate(), array_merge([
            'title' => $this->getPageTitle($entity),
            'entity' => $entity,
            'form' => [
                'action' => $this->getFormAction($entity)
            ],
        ], $this->getCommonVariables($request), $this->getAdditionalVariables($request)));
    }

    /**
     * Deletes a special date.
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     *
     * @return Response Redirect to the special date list.
     *
     */
    public function delete(Request $request, Response $response): Response
    {
        $entity_id = $request->getAttribute('id');
        $this->getEntityManager()->delete($entity_id);

        // Add success flash message
        $this->flashMessages->success('Item deleted successfully.');

        return $response->withStatus(302)->withHeader('Location', $this->getDeleteRedirectUrl($entity_id));
    }
}
