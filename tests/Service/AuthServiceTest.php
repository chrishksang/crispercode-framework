<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service;

use CrisperCode\Entity\User;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\LoginAttemptManager;
use CrisperCode\EntityManager\RememberTokenManager;
use CrisperCode\Service\AuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AuthService.
 */
class AuthServiceTest extends TestCase
{
    private AuthService $authService;
    private EntityFactory&MockObject $entityFactoryMock;
    private LoginAttemptManager&MockObject $loginAttemptManagerMock;
    private RememberTokenManager&MockObject $rememberTokenManagerMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any existing session data
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->entityFactoryMock = $this->createMock(EntityFactory::class);
        $this->loginAttemptManagerMock = $this->createMock(LoginAttemptManager::class);
        $this->rememberTokenManagerMock = $this->createMock(RememberTokenManager::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->authService = new AuthService(
            $this->entityFactoryMock,
            $this->loginAttemptManagerMock,
            $this->rememberTokenManagerMock,
            $this->loggerMock
        );
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        parent::tearDown();
    }

    public function testHashPasswordReturnsHashedString(): void
    {
        $password = 'testpassword123';

        $hash = $this->authService->hashPassword($password);

        $this->assertNotSame($password, $hash);
        $this->assertNotEmpty($hash);
        $this->assertTrue(strlen($hash) > 20);
    }

    public function testVerifyPasswordReturnsTrueForMatchingPassword(): void
    {
        $password = 'testpassword123';
        $hash = $this->authService->hashPassword($password);

        $this->assertTrue($this->authService->verifyPassword($password, $hash));
    }

    public function testVerifyPasswordReturnsFalseForWrongPassword(): void
    {
        $password = 'testpassword123';
        $wrongPassword = 'wrongpassword456';
        $hash = $this->authService->hashPassword($password);

        $this->assertFalse($this->authService->verifyPassword($wrongPassword, $hash));
    }

    public function testIsLoggedInReturnsFalseWhenNotLoggedIn(): void
    {
        $this->assertFalse($this->authService->isLoggedIn());
    }

    public function testIsLoggedInReturnsTrueAfterLogin(): void
    {
        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        $this->authService->login($userMock);

        $this->assertTrue($this->authService->isLoggedIn());
    }

    public function testLogoutClearsSession(): void
    {
        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        $this->authService->login($userMock);
        $this->assertTrue($this->authService->isLoggedIn());

        $this->authService->logout();

        $this->assertFalse($this->authService->isLoggedIn());
    }

    public function testGetUserReturnsNullWhenNotLoggedIn(): void
    {
        $this->assertNull($this->authService->getUser());
    }

    public function testGetUserReturnsUserAfterLogin(): void
    {
        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        $this->authService->login($userMock);

        $user = $this->authService->getUser();

        $this->assertNotNull($user);
        $this->assertSame($userMock, $user);
    }

    public function testFindUserByEmailDelegatesToEntityFactory(): void
    {
        $email = 'test@example.com';
        $userMock = $this->createMock(User::class);

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->with(User::class, ['email' => $email])
            ->willReturn($userMock);

        $result = $this->authService->findUserByEmail($email);

        $this->assertSame($userMock, $result);
    }

    public function testFindUserByEmailReturnsNullWhenNotFound(): void
    {
        $email = 'nonexistent@example.com';

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->with(User::class, ['email' => $email])
            ->willReturn(null);

        $result = $this->authService->findUserByEmail($email);

        $this->assertNull($result);
    }

    public function testDifferentPasswordsProduceDifferentHashes(): void
    {
        $hash1 = $this->authService->hashPassword('password1');
        $hash2 = $this->authService->hashPassword('password2');

        $this->assertNotSame($hash1, $hash2);
    }

    public function testSamePasswordProducesDifferentHashes(): void
    {
        // Due to salt, same password should produce different hashes
        $password = 'samepassword';
        $hash1 = $this->authService->hashPassword($password);
        $hash2 = $this->authService->hashPassword($password);

        $this->assertNotSame($hash1, $hash2);

        // But both should verify correctly
        $this->assertTrue($this->authService->verifyPassword($password, $hash1));
        $this->assertTrue($this->authService->verifyPassword($password, $hash2));
    }

    public function testAttemptLoginReturnsLockoutWhenRateLimited(): void
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('getLockoutSecondsRemaining')
            ->with($email, $ip)
            ->willReturn(120);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Login blocked due to rate limiting', $this->anything());

        $result = $this->authService->attemptLogin($email, 'password', $ip);

        $this->assertFalse($result['success']);
        $this->assertEquals(120, $result['lockoutSeconds']);
    }

    public function testAttemptLoginRecordsFailedAttempt(): void
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('getLockoutSecondsRemaining')
            ->willReturn(0);

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('recordAttempt')
            ->with($email, $ip, false);

        $result = $this->authService->attemptLogin($email, 'password', $ip);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid email or password.', $result['error']);
    }

    public function testAttemptLoginSuccessfulClearsFailedAttempts(): void
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';
        $password = 'correctpassword';

        $userMock = $this->createMock(User::class);
        $userMock->id = 1;
        $userMock->passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('getLockoutSecondsRemaining')
            ->willReturn(0);

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->willReturn($userMock);

        // Should record successful attempt AND clear failed attempts
        $this->loginAttemptManagerMock->expects($this->once())
            ->method('recordAttempt')
            ->with($email, $ip, true);

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('clearFailedAttempts')
            ->with($email, $ip);

        $result = $this->authService->attemptLogin($email, $password, $ip);

        $this->assertTrue($result['success']);
        $this->assertSame($userMock, $result['user']);
    }

    public function testLogoutEverywhereRevokesAllTokens(): void
    {
        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        $this->authService->login($userMock);

        $this->rememberTokenManagerMock->expects($this->once())
            ->method('revokeAllForUser')
            ->with(42);

        $this->authService->logout(true);
    }

    public function testAttemptLoginStoresEncryptionKeyInSession(): void
    {
        $email = 'test@example.com';
        $ip = '127.0.0.1';
        $password = 'correctpassword';

        $userMock = $this->createMock(User::class);
        $userMock->id = 42;
        $userMock->passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('getLockoutSecondsRemaining')
            ->willReturn(0);

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->willReturn($userMock);

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('recordAttempt');

        $this->loginAttemptManagerMock->expects($this->once())
            ->method('clearFailedAttempts');

        $result = $this->authService->attemptLogin($email, $password, $ip);

        $this->assertTrue($result['success']);

        // The encryption key should be set in the session after successful login
        $encryptionKey = $this->authService->getEncryptionKey();
        $this->assertNotNull($encryptionKey, 'Encryption key should be stored in session after attemptLogin');
        $this->assertSame(32, strlen($encryptionKey), 'Encryption key should be 32 bytes');
    }

    public function testLoginWithRememberMePassesEncryptionKeyToTokenManager(): void
    {
        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        // Pre-set an encryption key in the session (as if from a successful login)
        $_SESSION['auth_encryption_key'] = 'test-encryption-key-123456789012';

        $this->rememberTokenManagerMock->expects($this->once())
            ->method('createToken')
            ->with(
                42,
                'Mozilla/5.0',
                '127.0.0.1',
                'test-encryption-key-123456789012'
            )
            ->willReturn([
                'series' => 'test-series',
                'token' => 'test-token',
                'expires' => time() + 86400,
            ]);

        $this->authService->login($userMock, true, 'Mozilla/5.0', '127.0.0.1');

        $this->assertTrue($this->authService->isLoggedIn());
    }

    public function testAttemptRememberMeLoginRestoresEncryptionKey(): void
    {
        $series = 'test-series';
        $token = 'test-token';
        $encryptionKey = 'restored-encryption-key-12345678';
        $cookieValue = base64_encode($series . ':' . $token);

        $_COOKIE[RememberTokenManager::COOKIE_NAME] = $cookieValue;

        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        $this->rememberTokenManagerMock->expects($this->once())
            ->method('validateAndRotateToken')
            ->with($series, $token, null, null)
            ->willReturn([
                'userId' => 42,
                'newToken' => 'new-token',
                'encryptionKey' => $encryptionKey,
            ]);

        $this->entityFactoryMock->expects($this->once())
            ->method('findById')
            ->with(User::class, 42)
            ->willReturn($userMock);

        $result = $this->authService->attemptRememberMeLogin();

        $this->assertTrue($result);
        $this->assertTrue($this->authService->isLoggedIn());

        // Verify encryption key was restored to session
        $restoredKey = $this->authService->getEncryptionKey();
        $this->assertEquals($encryptionKey, $restoredKey);
    }

    public function testAttemptRememberMeLoginWithNullEncryptionKey(): void
    {
        $series = 'test-series';
        $token = 'test-token';
        $cookieValue = base64_encode($series . ':' . $token);

        $_COOKIE[RememberTokenManager::COOKIE_NAME] = $cookieValue;

        $userMock = $this->createMock(User::class);
        $userMock->id = 42;

        $this->rememberTokenManagerMock->expects($this->once())
            ->method('validateAndRotateToken')
            ->with($series, $token, null, null)
            ->willReturn([
                'userId' => 42,
                'newToken' => 'new-token',
                'encryptionKey' => null,
            ]);

        $this->entityFactoryMock->expects($this->once())
            ->method('findById')
            ->with(User::class, 42)
            ->willReturn($userMock);

        $result = $this->authService->attemptRememberMeLogin();

        $this->assertTrue($result);
        $this->assertTrue($this->authService->isLoggedIn());

        // Verify encryption key is not set in session
        $restoredKey = $this->authService->getEncryptionKey();
        $this->assertNull($restoredKey);
    }
}
