<?php

namespace CrisperCode\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Csrf\Guard;
use Slim\Views\Twig;

/**
 * Middleware that injects CSRF token data into Twig templates as global variables.
 *
 * This makes CSRF token keys and values available to all templates for form protection,
 * allowing forms to include the necessary hidden fields for CSRF validation.
 */
class CsrfMiddleware
{
    public function __construct(
        private Twig $twig,
        private Guard $guard,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Retrieves CSRF token keys and values from the request (set by the Slim CSRF Guard middleware)
     * and injects them into the Twig environment as global variables for use in templates.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @param RequestHandlerInterface $handler The next request handler.
     * @return ResponseInterface The response from the next handler.
     */
    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nameKey = $this->guard->getTokenNameKey();
        $valueKey = $this->guard->getTokenValueKey();
        $name = $request->getAttribute($nameKey);
        $value = $request->getAttribute($valueKey);

        try {
            $this->twig->getEnvironment()->addGlobal('csrf', [
                'keys' => [
                    'name' => $nameKey,
                    'value' => $valueKey
                ],
                'name' => $name,
                'value' => $value
            ]);
        } catch (\LogicException $e) {
            // In FrankenPHP worker mode, Twig may already be initialized.
            $this->logger?->debug('CSRF Twig global could not be set (Twig likely already initialized)', [
                'exception' => $e,
            ]);
        }

        return $handler->handle($request);
    }
}
