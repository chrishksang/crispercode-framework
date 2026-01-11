<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Middleware;

use CrisperCode\Entity\User;
use CrisperCode\Middleware\AuthMiddleware;
use CrisperCode\Service\AuthService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests for AuthMiddleware.
 */
class AuthMiddlewareTest extends TestCase
{
    private AuthService&MockObject $authServiceMock;
    private ResponseFactoryInterface&MockObject $responseFactoryMock;
    private ServerRequestInterface&MockObject $requestMock;
    private RequestHandlerInterface&MockObject $handlerMock;

    protected function setUp(): void
    {
        $this->authServiceMock = $this->createMock(AuthService::class);
        $this->responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);
        $this->requestMock = $this->createMock(ServerRequestInterface::class);
        $this->handlerMock = $this->createMock(RequestHandlerInterface::class);
    }

    public function testRedirectsToLoginWhenNotAuthenticated(): void
    {
        // Arrange
        $this->authServiceMock->method('getUser')->willReturn(null);

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn('/protected-page');
        $this->requestMock->method('getUri')->willReturn($uriMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('withHeader')
            ->with('Location', '/login')
            ->willReturnSelf();

        $this->responseFactoryMock->method('createResponse')
            ->with(302)
            ->willReturn($responseMock);

        // Act
        $middleware = new AuthMiddleware($this->authServiceMock, $this->responseFactoryMock);
        $result = $middleware($this->requestMock, $this->handlerMock);

        // Assert
        $this->assertSame($responseMock, $result);
    }

    public function testPassesRequestToHandlerWhenAuthenticated(): void
    {
        // Arrange
        $userMock = $this->createMock(User::class);
        $this->authServiceMock->method('getUser')->willReturn($userMock);

        $modifiedRequest = $this->createMock(ServerRequestInterface::class);
        $this->requestMock->method('withAttribute')
            ->with('user', $userMock)
            ->willReturn($modifiedRequest);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $this->handlerMock->expects($this->once())
            ->method('handle')
            ->with($modifiedRequest)
            ->willReturn($expectedResponse);

        // Act
        $middleware = new AuthMiddleware($this->authServiceMock, $this->responseFactoryMock);
        $result = $middleware($this->requestMock, $this->handlerMock);

        // Assert
        $this->assertSame($expectedResponse, $result);
    }

    public function testAddsUserToRequestAttributes(): void
    {
        // Arrange
        $userMock = $this->createMock(User::class);
        $this->authServiceMock->method('getUser')->willReturn($userMock);

        $modifiedRequest = $this->createMock(ServerRequestInterface::class);
        $this->requestMock->expects($this->once())
            ->method('withAttribute')
            ->with('user', $userMock)
            ->willReturn($modifiedRequest);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $this->handlerMock->method('handle')->willReturn($expectedResponse);

        // Act
        $middleware = new AuthMiddleware($this->authServiceMock, $this->responseFactoryMock);
        $middleware($this->requestMock, $this->handlerMock);
    }

    public function testStoresIntendedUrlInSession(): void
    {
        // Start session for this test
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Arrange
        $this->authServiceMock->method('getUser')->willReturn(null);

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn('/dashboard');
        $this->requestMock->method('getUri')->willReturn($uriMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('withHeader')->willReturnSelf();

        $this->responseFactoryMock->method('createResponse')
            ->with(302)
            ->willReturn($responseMock);

        // Act
        $middleware = new AuthMiddleware($this->authServiceMock, $this->responseFactoryMock);
        $middleware($this->requestMock, $this->handlerMock);

        // Assert
        $this->assertSame('/dashboard', $_SESSION['intended_url']);

        // Cleanup
        unset($_SESSION['intended_url']);
    }

    public function testDoesNotStoreLoginPathAsIntendedUrl(): void
    {
        // Start session for this test
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear any previous intended_url
        unset($_SESSION['intended_url']);

        // Arrange
        $this->authServiceMock->method('getUser')->willReturn(null);

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn('/login');
        $this->requestMock->method('getUri')->willReturn($uriMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('withHeader')->willReturnSelf();

        $this->responseFactoryMock->method('createResponse')
            ->with(302)
            ->willReturn($responseMock);

        // Act
        $middleware = new AuthMiddleware($this->authServiceMock, $this->responseFactoryMock);
        $middleware($this->requestMock, $this->handlerMock);

        // Assert - login path should not be stored as intended URL
        $this->assertArrayNotHasKey('intended_url', $_SESSION);
    }
}
