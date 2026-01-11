<?php

namespace Tests\CrisperCode\Middleware;

use CrisperCode\Cache\Cache;
use CrisperCode\Middleware\CacheMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CacheMiddlewareTest extends TestCase
{
    public function testCacheHit(): void
    {
        $cache = $this->createMock(Cache::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $middleware = new CacheMiddleware($cache, $responseFactory);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/test');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn(['id' => '123']);
        $request->method('getAttribute')
            ->willReturnCallback(function ($key, $default = null) {
                return $key === 'cache_expiry' ? 600 : $default;
            });

        $cachedPayload = '{"cached": true}';
        $cache->expects($this->once())
            ->method('get')
            ->with('cache_middleware:' . md5('/api/test?id=123'))
            ->willReturn($cachedPayload);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $responseBody = $this->createMock(StreamInterface::class);

        // Handler should not be called on cache hit
        $handler->expects($this->never())->method('handle');

        $responseFactory->expects($this->once())
            ->method('createResponse')
            ->willReturn($response);

        $response->method('getBody')->willReturn($responseBody);
        $response->method('withHeader')->willReturnSelf();
        $responseBody->expects($this->once())
            ->method('write')
            ->with($cachedPayload);

        $result = $middleware($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testCacheMiss(): void
    {
        $cache = $this->createMock(Cache::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $middleware = new CacheMiddleware($cache, $responseFactory);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/test');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn(['id' => '456']);
        $request->method('getAttribute')
            ->willReturnCallback(function ($key, $default = null) {
                return $key === 'cache_expiry' ? 600 : $default;
            });

        $cache->expects($this->once())
            ->method('get')
            ->with('cache_middleware:' . md5('/api/test?id=456'))
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $responseBody = $this->createMock(StreamInterface::class);

        $responsePayload = '{"result": "data"}';
        $responseBody->method('rewind');
        $responseBody->method('getContents')->willReturn($responsePayload);

        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $response->method('getBody')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('withHeader')->willReturnSelf();

        $cache->expects($this->once())
            ->method('set')
            ->with('cache_middleware:' . md5('/api/test?id=456'), $responsePayload, 600);

        $result = $middleware($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testCustomCacheKey(): void
    {
        $cache = $this->createMock(Cache::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $middleware = new CacheMiddleware($cache, $responseFactory);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(function ($key, $default = null) {
                if ($key === 'cache_key') {
                    return 'cache_middleware:backtest:123';
                }
                if ($key === 'cache_expiry') {
                    return 600;
                }
                return $default;
            });

        $cache->expects($this->once())
            ->method('get')
            ->with('cache_middleware:backtest:123')
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $responseBody = $this->createMock(StreamInterface::class);

        $responsePayload = '{"backtest": "data"}';
        $responseBody->method('rewind');
        $responseBody->method('getContents')->willReturn($responsePayload);

        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $response->method('getBody')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('withHeader')->willReturnSelf();

        $cache->expects($this->once())
            ->method('set')
            ->with('cache_middleware:backtest:123', $responsePayload, 600);

        $middleware($request, $handler);
    }

    public function testDoesNotCacheNon200Responses(): void
    {
        $cache = $this->createMock(Cache::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $middleware = new CacheMiddleware($cache, $responseFactory);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/test');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(function ($key, $default = null) {
                return $key === 'cache_expiry' ? 600 : $default;
            });

        $cache->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $responseBody = $this->createMock(StreamInterface::class);

        $responseBody->method('rewind');
        $responseBody->method('getContents')->willReturn('{"error": "not found"}');

        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $response->method('getBody')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('withHeader')->willReturnSelf();

        $cache->expects($this->never())->method('set');

        $middleware($request, $handler);
    }

    public function testDoesNotCacheEmptyResponses(): void
    {
        $cache = $this->createMock(Cache::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $middleware = new CacheMiddleware($cache, $responseFactory);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/api/test');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(function ($key, $default = null) {
                return $key === 'cache_expiry' ? 600 : $default;
            });

        $cache->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $responseBody = $this->createMock(StreamInterface::class);

        $responseBody->method('rewind');
        $responseBody->method('getContents')->willReturn('');

        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($response);

        $response->method('getBody')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('withHeader')->willReturnSelf();

        $cache->expects($this->never())->method('set');

        $middleware($request, $handler);
    }

    public function testBacktestSpecificCacheKey(): void
    {
        $cache = $this->createMock(Cache::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $middleware = new CacheMiddleware($cache, $responseFactory);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(function ($key, $default = null) {
                if ($key === 'backtest_id') {
                    return '456';
                }
                if ($key === 'cache_expiry') {
                    return 600;
                }
                return $default;
            });

        $cache->expects($this->once())
            ->method('get')
            ->with('cache_middleware:backtest:456')
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $responseBody = $this->createMock(StreamInterface::class);

        $responsePayload = '{"backtest": "results"}';
        $responseBody->method('rewind');
        $responseBody->method('getContents')->willReturn($responsePayload);

        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $response->method('getBody')->willReturn($responseBody);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('withHeader')->willReturnSelf();

        $cache->expects($this->once())
            ->method('set')
            ->with('cache_middleware:backtest:456', $responsePayload, 600);

        $middleware($request, $handler);
    }
}
