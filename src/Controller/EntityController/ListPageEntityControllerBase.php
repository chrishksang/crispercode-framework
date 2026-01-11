<?php

namespace CrisperCode\Controller\EntityController;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class ListPageEntityControllerBase extends PageEntityControllerBase
{
    /**
     * Gets the default sort field for the list.
     *
     * @return string The default sort field.
     */
    protected function getDefaultSort(): string
    {
        $attribute = $this->getControllerAttribute();

        if ($attribute->defaultSort === null) {
            return 'id'; // fallback default
        }

        return $attribute->defaultSort;
    }

    /**
     * Gets the default sort order for the list.
     *
     * @return string The default sort order ('asc' or 'desc').
     */
    protected function getDefaultOrder(): string
    {
        return 'desc';
    }

    /**
     * Gets pagination parameters from request query params.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return array{page: int, per_page: int} Pagination parameters.
     */
    protected function getPaginationParams(Request $request): array
    {
        $queryParams = $request->getQueryParams();
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $per_page = (int) ($queryParams['per_page'] ?? 10);

        // Validate per_page is one of the allowed values
        if (!in_array($per_page, [10, 25, 50], true)) {
            $per_page = 10;
        }

        return [
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Calculates pagination metadata.
     *
     * @param int $page Current page number.
     * @param int $per_page Items per page.
     * @param int $total_count Total number of items.
     *
     * @return array{page: int, per_page: int, total_count: int, total_pages: int, offset: int} Pagination metadata.
     */
    protected function getPaginationMetadata(int $page, int $per_page, int $total_count): array
    {
        $total_pages = max(1, (int) ceil($total_count / $per_page));

        // Clamp page to valid range
        $page = max(1, min($page, $total_pages));

        $offset = ($page - 1) * $per_page;

        return [
            'page' => $page,
            'per_page' => $per_page,
            'total_count' => $total_count,
            'total_pages' => $total_pages,
            'offset' => $offset,
        ];
    }

    /**
     * Lists all entities.
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     *
     * @return Response Rendered list page.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function list(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $sort = $queryParams['sort'] ?? $this->getDefaultSort();
        $order = $queryParams['order'] ?? $this->getDefaultOrder();

        // Get pagination parameters
        $paginationParams = $this->getPaginationParams($request);
        $page = $paginationParams['page'];
        $per_page = $paginationParams['per_page'];

        // Get total count for pagination
        $total_count = $this->getEntityManager()->count();

        // Calculate pagination metadata
        $pagination = $this->getPaginationMetadata($page, $per_page, $total_count);

        $entities = $this->getEntityManager()->loadMultiple([
            'sort' => $sort,
            'order' => $order,
            'limit' => $pagination['per_page'],
            'offset' => $pagination['offset'],
        ]);

        return $this->view->render($response, $this->getPageTemplate(), array_merge([
            'title' => $this->getPageTitle(null),
            'entities' => $entities,
            'current_sort' => $sort,
            'current_order' => $order,
            'pagination' => $pagination,
        ], $this->getCommonVariables($request)));
    }
}
