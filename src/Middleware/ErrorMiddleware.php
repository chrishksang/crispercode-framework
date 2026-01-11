<?php

declare(strict_types=1);

namespace CrisperCode\Middleware;

use CrisperCode\Config\FrameworkConfig;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Error handling middleware for displaying themed error pages in production.
 *
 * In development mode, errors are passed through for debugging.
 * In production mode, a user-friendly error page is displayed.
 */
class ErrorMiddleware implements MiddlewareInterface
{
    private bool $displayErrorDetails;
    private string $appName;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private Twig $view,
        private LoggerInterface $logger,
        FrameworkConfig $config
    ) {
        $this->displayErrorDetails = $config->isDevelopment();
        $this->appName = $config->getAppName();
    }

    /**
     * Process the request and catch any unhandled exceptions.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    /**
     * Handle the caught exception.
     */
    private function handleException(ServerRequestInterface $request, \Throwable $exception): ResponseInterface
    {
        // Always log the error
        $this->logger->error('Unhandled exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'uri' => (string) $request->getUri(),
            'method' => $request->getMethod(),
        ]);

        if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
            $response = $this->responseFactory->createResponse(404);
            return $this->view->render($response, '404.twig', [
                'title' => 'Page Not Found',
                'error_code' => 404
            ]);
        }

        // In development mode, re-throw to show detailed error
        if ($this->displayErrorDetails) {
            throw $exception;
        }

        // In production, show themed error page
        $response = $this->responseFactory->createResponse(500);

        try {
            return $this->view->render($response, 'error.twig', [
                'title' => 'Oops! Something went wrong',
                'error_code' => 500,
            ]);
        } catch (\Throwable) {
            // If template rendering fails, return a basic HTML response
            $response->getBody()->write($this->getFallbackHtml());
            return $response;
        }
    }

    /**
     * Get fallback HTML if template rendering fails.
     */
    private function getFallbackHtml(): string
    {
        $appName = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - {$appName}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f1a;
            color: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
        }
        h1 { color: #ffffff; margin-bottom: 1rem; }
        p { color: #94a3b8; }
        a { color: #6366f1; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div>
        <h1>Oops! Something went wrong</h1>
        <p>We're sorry, but something unexpected happened.</p>
        <p><a href="/">Go back home</a></p>
    </div>
</body>
</html>
HTML;
    }
}
