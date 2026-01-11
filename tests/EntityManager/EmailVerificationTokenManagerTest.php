<?php

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\Entity\EmailVerificationToken;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\EmailVerificationTokenManager;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailVerificationTokenManager.
 */
class EmailVerificationTokenManagerTest extends TestCase
{
    private EmailVerificationTokenManager $manager;
    private MeekroDB&MockObject $dbMock;
    private EntityFactory&MockObject $entityFactoryMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->entityFactoryMock = $this->createMock(EntityFactory::class);

        $this->manager = new EmailVerificationTokenManager(
            $this->dbMock,
            $this->entityFactoryMock
        );
    }

    public function testCreateTokenGeneratesSecureTokens(): void
    {
        $tokenMock = $this->createMock(EmailVerificationToken::class);

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(EmailVerificationToken::class)
            ->willReturn($tokenMock);

        $tokenMock->expects($this->once())
            ->method('setCreatedAtNow');

        $tokenMock->expects($this->once())
            ->method('setExpiresInHours')
            ->with(24);

        $tokenMock->expects($this->once())
            ->method('save');

        $result = $this->manager->createToken(42);

        // Verify structure
        $this->assertArrayHasKey('selector', $result);
        $this->assertArrayHasKey('validator', $result);

        // Verify selector is 32 hex chars (16 bytes)
        $this->assertSame(32, strlen($result['selector']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['selector']);

        // Verify validator is 64 hex chars (32 bytes)
        $this->assertSame(64, strlen($result['validator']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['validator']);
    }

    public function testCreateTokenDeletesExistingTokensForUser(): void
    {
        $tokenMock = $this->createMock(EmailVerificationToken::class);

        $this->entityFactoryMock->method('create')
            ->willReturn($tokenMock);

        // Should delete existing tokens first
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                'DELETE FROM email_verification_tokens WHERE user_id = %i',
                42
            );

        $this->manager->createToken(42);
    }

    public function testCreateTokenWithCustomExpiry(): void
    {
        $tokenMock = $this->createMock(EmailVerificationToken::class);

        $this->entityFactoryMock->method('create')
            ->willReturn($tokenMock);

        $tokenMock->expects($this->once())
            ->method('setExpiresInHours')
            ->with(48);

        $this->manager->createToken(42, 48);
    }

    public function testFindBySelectorReturnsTokenWhenFound(): void
    {
        $row = [
            'id' => 1,
            'user_id' => 42,
            'selector' => 'abc123',
            'validator_hash' => 'hashed',
            'created_at' => '2024-01-01 00:00:00',
            'expires_at' => '2024-01-02 00:00:00',
            'used_at' => null,
        ];

        $tokenMock = $this->createMock(EmailVerificationToken::class);

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->with(
                'SELECT * FROM email_verification_tokens WHERE selector = %s',
                'abc123'
            )
            ->willReturn($row);

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(EmailVerificationToken::class, $row)
            ->willReturn($tokenMock);

        $result = $this->manager->findBySelector('abc123');

        $this->assertSame($tokenMock, $result);
    }

    public function testFindBySelectorReturnsNullWhenNotFound(): void
    {
        $this->dbMock->method('queryFirstRow')
            ->willReturn(null);

        $result = $this->manager->findBySelector('nonexistent');

        $this->assertNull($result);
    }

    public function testValidateTokenReturnsTokenForValidCredentials(): void
    {
        $plainValidator = 'test_validator';
        $hashedValidator = password_hash($plainValidator, PASSWORD_DEFAULT);

        $tokenMock = $this->createMock(EmailVerificationToken::class);
        $tokenMock->validatorHash = $hashedValidator;
        $tokenMock->method('isValid')->willReturn(true);

        $row = ['id' => 1, 'validator_hash' => $hashedValidator];

        $this->dbMock->method('queryFirstRow')
            ->willReturn($row);

        $this->entityFactoryMock->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateToken('selector', $plainValidator);

        $this->assertSame($tokenMock, $result);
    }

    public function testValidateTokenReturnsNullForExpiredToken(): void
    {
        $tokenMock = $this->createMock(EmailVerificationToken::class);
        $tokenMock->validatorHash = password_hash('test', PASSWORD_DEFAULT);
        $tokenMock->method('isValid')->willReturn(false);

        $this->dbMock->method('queryFirstRow')
            ->willReturn(['id' => 1]);

        $this->entityFactoryMock->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateToken('selector', 'test');

        $this->assertNull($result);
    }

    public function testValidateTokenReturnsNullForWrongValidator(): void
    {
        $tokenMock = $this->createMock(EmailVerificationToken::class);
        $tokenMock->validatorHash = password_hash('correct', PASSWORD_DEFAULT);
        $tokenMock->method('isValid')->willReturn(true);

        $this->dbMock->method('queryFirstRow')
            ->willReturn(['id' => 1]);

        $this->entityFactoryMock->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateToken('selector', 'wrong');

        $this->assertNull($result);
    }

    public function testMarkAsUsedSavesToken(): void
    {
        $tokenMock = $this->createMock(EmailVerificationToken::class);

        $tokenMock->expects($this->once())
            ->method('markAsUsed');

        $tokenMock->expects($this->once())
            ->method('save');

        $this->manager->markAsUsed($tokenMock);
    }

    public function testDeleteTokensForUserDeletesAllUserTokens(): void
    {
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                'DELETE FROM email_verification_tokens WHERE user_id = %i',
                42
            );

        $this->manager->deleteTokensForUser(42);
    }

    public function testFormatVerificationUrlCreatesCorrectUrl(): void
    {
        $url = EmailVerificationTokenManager::formatVerificationUrl(
            'https://example.com',
            'selector123',
            'validator456'
        );

        $this->assertSame(
            'https://example.com/verify-email/confirm?selector=selector123&token=validator456',
            $url
        );
    }

    public function testFormatVerificationUrlHandlesTrailingSlash(): void
    {
        $url = EmailVerificationTokenManager::formatVerificationUrl(
            'https://example.com/',
            'sel',
            'val'
        );

        $this->assertStringStartsWith('https://example.com/verify-email/confirm?', $url);
        $this->assertStringNotContainsString('//verify-email', $url);
    }
}
