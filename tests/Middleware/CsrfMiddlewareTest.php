<?php

namespace Tests\CrisperCode\Middleware;

use CrisperCode\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Twig\Environment;

class CsrfMiddlewareTest extends TestCase
{
    private $twigMock;
    private $guardMock;
    private $environmentMock;

    public function setUp(): void
    {
        $this->twigMock = $this->createMock(Twig::class);
        $this->guardMock = $this->createMock(Guard::class);
        $this->environmentMock = $this->createMock(Environment::class);

        $this->twigMock->method('getEnvironment')->willReturn($this->environmentMock);
    }

    public function testCsrfTokensAreRetrievedFromRequestAttributes(): void
    {
        // Arrange
        $nameKey = 'csrf_name';
        $valueKey = 'csrf_value';
        $tokenName = 'test_token_name';
        $tokenValue = 'test_token_value';

        $this->guardMock->method('getTokenNameKey')->willReturn($nameKey);
        $this->guardMock->method('getTokenValueKey')->willReturn($valueKey);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                [$nameKey, null, $tokenName],
                [$valueKey, null, $tokenValue],
            ]);

        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        // Expect the Twig environment to have addGlobal called with the correct CSRF data
        $this->environmentMock->expects($this->once())
            ->method('addGlobal')
            ->with('csrf', [
                'keys' => [
                    'name' => $nameKey,
                    'value' => $valueKey,
                ],
                'name' => $tokenName,
                'value' => $tokenValue,
            ]);

        // Act
        $middleware = new CsrfMiddleware($this->twigMock, $this->guardMock);
        $result = $middleware($request, $handler);

        // Assert
        $this->assertSame($response, $result);
    }

    public function testMiddlewarePassesRequestToNextHandler(): void
    {
        // Arrange
        $this->guardMock->method('getTokenNameKey')->willReturn('csrf_name');
        $this->guardMock->method('getTokenValueKey')->willReturn('csrf_value');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $response = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        // Act
        $middleware = new CsrfMiddleware($this->twigMock, $this->guardMock);
        $result = $middleware($request, $handler);

        // Assert
        $this->assertSame($response, $result);
    }
}
