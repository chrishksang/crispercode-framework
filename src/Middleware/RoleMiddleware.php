<?php

declare(strict_types=1);

namespace CrisperCode\Middleware;

use CrisperCode\EntityManager\UserRoleManager;
use CrisperCode\Enum\Roles;
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
     * @param UserRoleManager $userRoleManager Manager for checking user roles.
     * @param ResponseFactoryInterface $responseFactory Factory for creating responses.
     * @param Twig $view Twig view for rendering error pages.
     * @param array<Roles> $requiredRoles valid roles for this route (one of these is required).
     */
    public function __construct(
        private UserRoleManager $userRoleManager,
        private ResponseFactoryInterface $responseFactory,
        private Twig $view,
        private array $requiredRoles
    ) {
    }

    /**
     * Factory method to create an instance with specific roles requirement.
     *
     * @param array<Roles> $roles List of allowed roles.
     * @return string Class name for DI container (if used as string) or closure?
     *
     * Actually, in Slim, we often instantiate middleware or use a DI key.
     * But since we need to pass strict parameters ($roles) that vary per route,
     * we usually use a static helper or a class that takes arguments.
     *
     * However, standard DI in Slim 4 complicates passing dynamic args to middleware constructors
     * if the middleware is resolved from container.
     *
     * Better approach: explicit construction or a wrapper.
     *
     * Let's stick to simple constructor injection if we instantiate it manually.
     * Or better yet, make a static helper that returns a callable which resolves dependencies.
     */

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var \CrisperCode\Entity\User|null $user */
        $user = $request->getAttribute('user');

        if (!$user) {
            // Not authenticated - redirect to login or let AuthMiddleware handle it.
            // Assuming AuthMiddleware runs BEFORE this.
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
     * Helper to create the middleware closure/callable for use in routes.
     *
     * @param array<Roles> $roles
     * @return \Closure
     */
    public static function requireRoles(array $roles): \Closure
    {
        return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($roles) {
            /* @var \Slim\App $this */
            // Note: In Slim route callbacks, $this is bound to container if using Closure binding?
            // No, Slim 4 doesn't bind $this to container in middleware closures automatically.
            // We need to access container from somewhere or use a class.

            // Since we are adding this to the app, we might not have easy access to the container inside a static closure
            // unless we pass the container.

            // Simplest pattern for Slim 4 with params:
            // $app->get(...)->add(new RoleMiddleware($container->get(UserRoleManager::class), ..., $roles));

            // But we want a nice syntax like ->add(RoleMiddleware::requireRoles(...))

            // Let's rely on manual instantiation in the routes definition for now,
            // or resolving from a global container helper if available (bootstrap() returns container).

            // ... actually, let's keep it simple. We will instantiate it in the route definition callback
            // where we have access to $app (and thus the container).

            // Return just the class name doesn't work with args.

            throw new \RuntimeException('Use new RoleMiddleware(...) instead.');
        };
    }
}
