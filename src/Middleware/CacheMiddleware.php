<?php

declare(strict_types=1);

namespace CrisperCode\Middleware;

use CrisperCode\Cache\Cache;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that caches responses.
 *
 * Checks the cache for an existing response based on the request URI and query parameters.
 * If found, returns the cached response. Otherwise, allows the request to proceed and caches
 * the response for future requests.
 *
 * @package CrisperCode\Middleware
 */
class CacheMiddleware implements MiddlewareInterface
{
    /**
     * Default cache expiry time in seconds (10 minutes).
     */
    private const DEFAULT_EXPIRY = 600;

    public function __construct(
        private Cache $cache,
        private ResponseFactoryInterface $responseFactory
    ) {
    }

    /**
     * Process the request and handle caching.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @param RequestHandlerInterface $handler The next request handler.
     * @return ResponseInterface The response from cache or the next handler.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cacheKey = $this->getCacheKey($request);
        $cached = $this->cache->get($cacheKey);
        $expiry = $request->getAttribute('cache_expiry', self::DEFAULT_EXPIRY);
        $lastModified = $request->getAttribute('cache_last_modified', REQUEST_TIME);

        if ($cached !== null) {
            $etag = $this->buildEtag($cached);
            $response = $this->responseFactory->createResponse();
            $response->getBody()->write($cached);
            return $this->withCacheHeaders($response, $etag, $expiry, $lastModified)
                ->withHeader('X-Cache', 'HIT');
        }

        // Get response from handler
        $response = $handler->handle($request);
        $body = $response->getBody();
        $body->rewind();
        $payload = $body->getContents();

        // Cache successful responses with content
        if ($response->getStatusCode() === 200 && ($payload !== '' && $payload !== '0')) {
            $this->cache->set($cacheKey, $payload, $expiry);
        }

        $etag = $this->buildEtag($payload);

        return $this->withCacheHeaders($response, $etag, $expiry, $lastModified)
            ->withHeader('X-Cache', 'MISS');
    }

    /**
     * Adds common cache-related headers.
     */
    private function withCacheHeaders(
        ResponseInterface $response,
        string $etag,
        int $expiry,
        int $lastModified
    ): ResponseInterface {
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=' . $expiry)
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT')
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', $lastModified + $expiry) . ' GMT');
    }

    /**
     * Builds a weak ETag from the payload.
     */
    private function buildEtag(string $payload): string
    {
        return 'W/"' . md5($payload) . '"';
    }

    /**
     * Allows invocation as a callable for compatibility.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @param RequestHandlerInterface $handler The next request handler.
     * @return ResponseInterface The response from cache or the next handler.
     */
    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->process($request, $handler);
    }

    /**
     * Generates a unique cache key for the current request.
     *
     * Uses a custom cache key from request attributes if available,
     * otherwise generates one from the URI and query parameters.
     * Special handling for backtest routes to use a cleaner cache key.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @return string The cache key.
     */
    private function getCacheKey(ServerRequestInterface $request): string
    {
        $customKey = $request->getAttribute('cache_key');
        if ($customKey !== null) {
            return $customKey;
        }

        // Special case for backtest routes - use backtest_id for cleaner cache key
        $backtestId = $request->getAttribute('backtest_id');
        if ($backtestId !== null) {
            return 'cache_middleware:backtest:' . $backtestId;
        }

        return 'cache_middleware:' . md5(
            $request->getUri()->getPath() . '?' . http_build_query($request->getQueryParams())
        );
    }
}
