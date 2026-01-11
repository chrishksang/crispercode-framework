<?php

declare(strict_types=1);

namespace CrisperCode\Middleware;

use CrisperCode\Service\AuthService;
use CrisperCode\Utils\IpAddressHelper;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that protects routes by requiring authentication.
 *
 * If the user is not logged in, attempts remember me authentication.
 * If still not authenticated, redirects to the login page.
 * If authenticated, the User entity is added to the request attributes.
 *
 * @package CrisperCode\Middleware
 */
class AuthMiddleware
{
    private const LOGIN_PATH = '/login';

    public function __construct(
        private AuthService $authService,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    /**
     * Checks if the user is authenticated.
     *
     * First checks session, then attempts remember me login.
     * If authenticated, adds the user to request attributes and continues.
     * If not authenticated, redirects to the login page.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @param RequestHandlerInterface $handler The next request handler.
     * @return ResponseInterface The response.
     */
    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->authService->getUser();

        // If no session user, try remember me
        if (!$user instanceof \CrisperCode\Entity\User) {
            $userAgent = $request->getHeaderLine('User-Agent');
            $ipAddress = IpAddressHelper::getClientIp($request);

            if ($this->authService->attemptRememberMeLogin($userAgent, $ipAddress)) {
                $user = $this->authService->getUser();
            }
        }

        if (!$user instanceof \CrisperCode\Entity\User) {
            // Store the intended URL for redirect after login
            $intendedUrl = (string) $request->getUri();
            if ($intendedUrl !== self::LOGIN_PATH) {
                $_SESSION['intended_url'] = $intendedUrl;
            }

            // Redirect to login
            $response = $this->responseFactory->createResponse(302);
            return $response->withHeader('Location', self::LOGIN_PATH);
        }

        // Add user to request attributes for use in controllers
        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }
}
