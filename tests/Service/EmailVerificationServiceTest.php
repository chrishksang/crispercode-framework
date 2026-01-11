<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service;

use CrisperCode\Entity\EmailVerificationToken;
use CrisperCode\Entity\User;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\EmailVerificationTokenManager;
use CrisperCode\Service\Email\EmailServiceInterface;
use CrisperCode\Service\EmailVerificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Tests for EmailVerificationService.
 */
class EmailVerificationServiceTest extends TestCase
{
    private EmailVerificationService $service;
    private EntityFactory&MockObject $entityFactoryMock;
    private EmailVerificationTokenManager&MockObject $tokenManagerMock;
    private EmailServiceInterface&MockObject $emailServiceMock;
    private Twig&MockObject $twigMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityFactoryMock = $this->createMock(EntityFactory::class);
        $this->tokenManagerMock = $this->createMock(EmailVerificationTokenManager::class);
        $this->emailServiceMock = $this->createMock(EmailServiceInterface::class);
        $this->twigMock = $this->createMock(Twig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->service = new EmailVerificationService(
            $this->entityFactoryMock,
            $this->tokenManagerMock,
            $this->emailServiceMock,
            $this->twigMock,
            $this->loggerMock,
            'TestApp'
        );
    }

    public function testSendVerificationEmailCreatesTokenAndSendsEmail(): void
    {
        $user = $this->createMock(User::class);
        $user->id = 42;
        $user->email = 'test@example.com';

        $this->tokenManagerMock->expects($this->once())
            ->method('createToken')
            ->with(42)
            ->willReturn(['selector' => 'abc123', 'validator' => 'xyz789']);

        $this->twigMock->expects($this->once())
            ->method('fetch')
            ->willReturn('<html>Email content</html>');

        $this->emailServiceMock->expects($this->once())
            ->method('send')
            ->with(
                'test@example.com',
                'Verify your email address - TestApp',
                '<html>Email content</html>',
                $this->isType('string')
            )
            ->willReturn(true);

        $result = $this->service->sendVerificationEmail($user, 'https://example.com');

        $this->assertTrue($result);
    }

    public function testSendVerificationEmailReturnsFalseWhenUserHasNoId(): void
    {
        $user = $this->createMock(User::class);
        // User has no ID set

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Cannot send verification email: user has no ID');

        $result = $this->service->sendVerificationEmail($user, 'https://example.com');

        $this->assertFalse($result);
    }

    public function testSendVerificationEmailReturnsFalseWhenEmailFails(): void
    {
        $user = $this->createMock(User::class);
        $user->id = 42;
        $user->email = 'test@example.com';

        $this->tokenManagerMock->method('createToken')
            ->willReturn(['selector' => 'abc', 'validator' => 'xyz']);

        $this->twigMock->method('fetch')
            ->willReturn('<html>Content</html>');

        $this->emailServiceMock->method('send')
            ->willReturn(false);

        $result = $this->service->sendVerificationEmail($user, 'https://example.com');

        $this->assertFalse($result);
    }

    public function testVerifyEmailMarksUserAsVerified(): void
    {
        $token = $this->createMock(EmailVerificationToken::class);
        $token->userId = 42;

        $user = $this->createMock(User::class);
        $user->id = 42;
        $user->email = 'test@example.com';
        $user->emailVerified = false;

        $this->tokenManagerMock->expects($this->once())
            ->method('validateToken')
            ->with('selector123', 'validator456')
            ->willReturn($token);

        $this->entityFactoryMock->expects($this->once())
            ->method('findById')
            ->with(User::class, 42)
            ->willReturn($user);

        $user->expects($this->once())
            ->method('markEmailVerified');

        $user->expects($this->once())
            ->method('save');

        $this->tokenManagerMock->expects($this->once())
            ->method('markAsUsed')
            ->with($token);

        $result = $this->service->verifyEmail('selector123', 'validator456');

        $this->assertSame($user, $result);
    }

    public function testVerifyEmailReturnsNullForInvalidToken(): void
    {
        $this->tokenManagerMock->expects($this->once())
            ->method('validateToken')
            ->with('invalid', 'token')
            ->willReturn(null);

        $result = $this->service->verifyEmail('invalid', 'token');

        $this->assertNull($result);
    }

    public function testVerifyEmailReturnsNullWhenUserNotFound(): void
    {
        $token = $this->createMock(EmailVerificationToken::class);
        $token->userId = 999;

        $this->tokenManagerMock->method('validateToken')
            ->willReturn($token);

        $this->entityFactoryMock->method('findById')
            ->willReturn(null);

        $result = $this->service->verifyEmail('selector', 'validator');

        $this->assertNull($result);
    }

    public function testVerifyEmailHandlesAlreadyVerifiedUser(): void
    {
        $token = $this->createMock(EmailVerificationToken::class);
        $token->userId = 42;

        $user = $this->createMock(User::class);
        $user->id = 42;
        $user->emailVerified = true;

        $this->tokenManagerMock->method('validateToken')
            ->willReturn($token);

        $this->entityFactoryMock->method('findById')
            ->willReturn($user);

        // Should still mark token as used
        $this->tokenManagerMock->expects($this->once())
            ->method('markAsUsed')
            ->with($token);

        // Should NOT call markEmailVerified again
        $user->expects($this->never())
            ->method('markEmailVerified');

        $result = $this->service->verifyEmail('selector', 'validator');

        $this->assertSame($user, $result);
    }

    public function testNeedsVerificationReturnsTrueForUnverifiedUser(): void
    {
        $user = $this->createMock(User::class);
        $user->emailVerified = false;

        $this->assertTrue($this->service->needsVerification($user));
    }

    public function testNeedsVerificationReturnsFalseForVerifiedUser(): void
    {
        $user = $this->createMock(User::class);
        $user->emailVerified = true;

        $this->assertFalse($this->service->needsVerification($user));
    }

    public function testResendVerificationEmailSendsToExistingUnverifiedUser(): void
    {
        $user = $this->createMock(User::class);
        $user->id = 42;
        $user->email = 'test@example.com';
        $user->emailVerified = false;

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->with(User::class, ['email' => 'test@example.com'])
            ->willReturn($user);

        $this->tokenManagerMock->method('createToken')
            ->willReturn(['selector' => 'abc', 'validator' => 'xyz']);

        $this->twigMock->method('fetch')
            ->willReturn('<html>Content</html>');

        $this->emailServiceMock->expects($this->once())
            ->method('send')
            ->willReturn(true);

        $result = $this->service->resendVerificationEmail('test@example.com', 'https://example.com');

        $this->assertTrue($result);
    }

    public function testResendVerificationEmailReturnsTrueForNonexistentEmail(): void
    {
        $this->entityFactoryMock->method('findOneBy')
            ->willReturn(null);

        // Should NOT try to send email
        $this->emailServiceMock->expects($this->never())
            ->method('send');

        // But should still return true (don't reveal email doesn't exist)
        $result = $this->service->resendVerificationEmail('nonexistent@example.com', 'https://example.com');

        $this->assertTrue($result);
    }

    public function testResendVerificationEmailReturnsTrueForAlreadyVerifiedEmail(): void
    {
        $user = $this->createMock(User::class);
        $user->emailVerified = true;

        $this->entityFactoryMock->method('findOneBy')
            ->willReturn($user);

        // Should NOT try to send email
        $this->emailServiceMock->expects($this->never())
            ->method('send');

        // But should still return true (don't reveal email is already verified)
        $result = $this->service->resendVerificationEmail('verified@example.com', 'https://example.com');

        $this->assertTrue($result);
    }
}
