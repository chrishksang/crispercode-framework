<?php

namespace Tests\CrisperCode\Middleware;

use CrisperCode\Middleware\RequestLoggingMiddleware;
use CrisperCode\Utils\PerformanceMonitor;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\Route;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;

class RequestLoggingMiddlewareTest extends TestCase
{
    public function testProcessRegistersShutdown()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $monitor = $this->createMock(PerformanceMonitor::class);

        $middleware = new RequestLoggingMiddleware($logger, $monitor);

        $request = $this->createMock(ServerRequestInterface::class);

        // Setup request to avoid crash during shutdown
        $routeParser = $this->createMock(RouteParserInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                [RouteContext::ROUTE_PARSER, null, $routeParser],
                [RouteContext::ROUTE, null, null],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $result = $middleware($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testLogRequest()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $monitor = $this->createMock(PerformanceMonitor::class);

        $monitor->expects($this->once())->method('end');
        $monitor->expects($this->once())->method('getMemoryUsage')->willReturn('10 KB');
        $monitor->expects($this->once())->method('getRenderTime')->willReturn('0.1 seconds');

        $middleware = new RequestLoggingMiddleware($logger, $monitor);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())->method('getMethod')->willReturn('GET');

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/test-path');
        $request->method('getUri')->willReturn($uri);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $request->method('getAttribute')
            ->willReturnMap([
                [RouteContext::ROUTE_PARSER, null, $routeParser],
                [RouteContext::ROUTING_RESULTS, null, $routingResults],
                [RouteContext::ROUTE, null, null],
            ]);

        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Request Performance',
                [
                    'method' => 'GET',
                    'route' => '/test-path',
                    'time' => '0.1 seconds',
                    'memory' => '10 KB'
                ]
            );

        $middleware->logRequest($request);
    }

    public function testLogRequestWithRoute()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $monitor = $this->createMock(PerformanceMonitor::class);

        $monitor->method('getMemoryUsage')->willReturn('10 KB');
        $monitor->method('getRenderTime')->willReturn('0.1 seconds');

        $middleware = new RequestLoggingMiddleware($logger, $monitor);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');

        $route = $this->createMock(Route::class);
        $route->method('getPattern')->willReturn('/api/resource/{id}');

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $request->method('getAttribute')
            ->willReturnMap([
                [RouteContext::ROUTE_PARSER, null, $routeParser],
                [RouteContext::ROUTING_RESULTS, null, $routingResults],
                [RouteContext::ROUTE, null, $route],
            ]);

        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Request Performance',
                [
                    'method' => 'POST',
                    'route' => '/api/resource/{id}',
                    'time' => '0.1 seconds',
                    'memory' => '10 KB'
                ]
            );

        $middleware->logRequest($request);
    }
}
