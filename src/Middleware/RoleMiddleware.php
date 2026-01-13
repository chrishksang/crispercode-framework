<?php

declare(strict_types=1);

namespace CrisperCode\Middleware;

use CrisperCode\EntityManager\UserRoleManager;
use CrisperCode\Enum\Roles;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Middleware that restricts access to users with specific roles.
 *
 * @package CrisperCode\Middleware
 */
class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param array<Roles> $requiredRoles Valid roles for this route (one of these is required).
     */
    public function __construct(
        private UserRoleManager $userRoleManager,
        private ResponseFactoryInterface $responseFactory,
        private Twig $view,
        private array $requiredRoles
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var \CrisperCode\Entity\User|null $user */
        $user = $request->getAttribute('user');

        if (!$user) {
            $response = $this->responseFactory->createResponse(302);
            return $response->withHeader('Location', '/login');
        }

        if (!$this->userRoleManager->hasAnyRole($user->id, $this->requiredRoles)) {
            $response = $this->responseFactory->createResponse(403);
            return $this->view->render($response, 'error/403.twig', [
                'message' => 'You do not have permission to access this resource.',
            ]);
        }

        return $handler->handle($request);
    }

    /**
     * Convenience helper for Slim apps using a PSR-11 container.
     *
     * Usage:
     *   $app->get('/admin', ...)
     *       ->add(RoleMiddleware::requireRoles($container, [Roles::ADMIN]));
     *
     * @param array<Roles> $roles
     */
    public static function requireRoles(ContainerInterface $container, array $roles): self
    {
        return new self(
            $container->get(UserRoleManager::class),
            $container->get(ResponseFactoryInterface::class),
            $container->get(Twig::class),
            $roles
        );
    }
}
