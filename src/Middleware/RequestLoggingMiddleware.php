<?php

namespace CrisperCode\Middleware;

use CrisperCode\Utils\PerformanceMonitor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

class RequestLoggingMiddleware
{
    public function __construct(
        private LoggerInterface $logger,
        private PerformanceMonitor $performanceMonitor
    ) {
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Log after handling (works in both traditional and FrankenPHP worker mode)
        $this->logRequest($request);

        return $response;
    }

    public function logRequest(ServerRequestInterface $request): void
    {
        $this->performanceMonitor->end();
        $memory = $this->performanceMonitor->getMemoryUsage();
        $time = $this->performanceMonitor->getRenderTime();

        $method = $request->getMethod();
        $pattern = null;

        try {
            $routeContext = RouteContext::fromRequest($request);
            $route = $routeContext->getRoute();
            $pattern = $route instanceof RouteInterface ? $route->getPattern() : null;
        } catch (\RuntimeException $e) {
            // Route context creation failed (e.g. routing not completed)
        }

        if ($pattern === null) {
            $pattern = $request->getUri()->getPath();
        }

        $this->logger->info('Request Performance', [
            'method' => $method,
            'route' => $pattern,
            'time' => $time,
            'memory' => $memory,
        ]);
    }
}
