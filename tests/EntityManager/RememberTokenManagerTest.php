<?php

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\Entity\RememberToken;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\RememberTokenManager;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RememberTokenManager.
 */
class RememberTokenManagerTest extends TestCase
{
    private MeekroDB&MockObject $dbMock;
    private EntityFactory&MockObject $entityFactoryMock;
    private RememberTokenManager $manager;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->entityFactoryMock = $this->createMock(EntityFactory::class);

        $this->manager = new RememberTokenManager($this->dbMock, $this->entityFactoryMock);
    }

    public function testCreateTokenReturnsTokenData(): void
    {
        $userId = 42;
        $userAgent = 'Mozilla/5.0';
        $ipAddress = '192.168.1.1';

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->expects($this->once())->method('setCreatedAtNow');
        $tokenMock->expects($this->once())->method('setExpiresIn')->with(30);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(RememberToken::class)
            ->willReturn($tokenMock);

        $result = $this->manager->createToken($userId, $userAgent, $ipAddress);

        $this->assertArrayHasKey('series', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires', $result);
        $this->assertEquals(64, strlen($result['series'])); // 32 bytes = 64 hex chars
        $this->assertEquals(64, strlen($result['token']));
        $this->assertGreaterThan(time(), $result['expires']);
    }

    public function testValidateAndRotateTokenReturnsNullForMissingSeries(): void
    {
        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn(null);

        $result = $this->manager->validateAndRotateToken('nonexistent', 'token');

        $this->assertNull($result);
    }

    public function testValidateAndRotateTokenReturnsNullForExpiredToken(): void
    {
        $expiredRow = [
            'id' => 1,
            'user_id' => 42,
            'series' => 'test-series',
            'token_hash' => password_hash('valid-token', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s', time() - 86400),
            'expires_at' => date('Y-m-d H:i:s', time() - 3600), // Expired 1 hour ago
            'last_used_at' => null,
            'user_agent' => null,
            'ip_address' => null,
        ];

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($expiredRow);

        // Should delete expired token
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE'));

        // Mock the loadFromValues to return a token that reports as expired
        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->method('isExpired')->willReturn(true);

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', 'valid-token');

        $this->assertNull($result);
    }

    public function testRevokeTokenDeletesBySeries(): void
    {
        $series = 'test-series-123';

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM remember_tokens WHERE series'),
                $series
            );

        $this->manager->revokeToken($series);
    }

    public function testRevokeAllForUserDeletesByUserId(): void
    {
        $userId = 42;

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM remember_tokens WHERE user_id'),
                $userId
            );

        $this->manager->revokeAllForUser($userId);
    }

    public function testGetActiveSessionsForUserReturnsTokens(): void
    {
        $userId = 42;

        $rows = [
            [
                'id' => 1,
                'user_id' => 42,
                'series' => 'series-1',
                'token_hash' => 'hash',
                'created_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + 86400),
                'last_used_at' => date('Y-m-d H:i:s'),
                'user_agent' => 'Chrome',
                'ip_address' => '192.168.1.1',
            ],
        ];

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($rows);

        $tokenMock = $this->createMock(RememberToken::class);
        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $sessions = $this->manager->getActiveSessionsForUser($userId);

        $this->assertCount(1, $sessions);
    }

    public function testCleanupExpiredReturnsDeletedCount(): void
    {
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE FROM remember_tokens WHERE expires_at'));

        $this->dbMock->expects($this->once())
            ->method('affectedRows')
            ->willReturn(5);

        $deleted = $this->manager->cleanupExpired();

        $this->assertEquals(5, $deleted);
    }

    public function testFormatCookieValueEncodesCorrectly(): void
    {
        $series = 'test-series';
        $token = 'test-token';

        $result = RememberTokenManager::formatCookieValue($series, $token);

        $this->assertEquals(base64_encode($series . ':' . $token), $result);
    }

    public function testParseCookieValueDecodesCorrectly(): void
    {
        $series = 'test-series';
        $token = 'test-token';
        $cookieValue = base64_encode($series . ':' . $token);

        $result = RememberTokenManager::parseCookieValue($cookieValue);

        $this->assertEquals(['series' => $series, 'token' => $token], $result);
    }

    public function testParseCookieValueReturnsNullForInvalidBase64(): void
    {
        $result = RememberTokenManager::parseCookieValue('not-valid-base64!!!');

        $this->assertNull($result);
    }

    public function testParseCookieValueReturnsNullForMissingDelimiter(): void
    {
        $cookieValue = base64_encode('no-delimiter-here');

        $result = RememberTokenManager::parseCookieValue($cookieValue);

        $this->assertNull($result);
    }

    public function testCreateTokenWithEncryptionKeyStoresEncryptedKey(): void
    {
        $userId = 42;
        $encryptionKey = 'my-secret-encryption-key-32bytes';

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->expects($this->once())->method('setCreatedAtNow');
        $tokenMock->expects($this->once())->method('setExpiresIn')->with(30);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(RememberToken::class)
            ->willReturn($tokenMock);

        $result = $this->manager->createToken($userId, null, null, $encryptionKey);

        $this->assertArrayHasKey('series', $result);
        $this->assertArrayHasKey('token', $result);
        // Decrypting with the returned token has to give the key back: asserting the
        // property is a string passes just as well when nothing was encrypted at all.
        $this->assertNotNull($tokenMock->encryptedKey);
        $this->assertSame($encryptionKey, $this->decryptWithToken($tokenMock->encryptedKey, $result['token']));
    }

    public function testCreateTokenWithNullEncryptionKeyDoesNotSetEncryptedKey(): void
    {
        $userId = 42;

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->expects($this->once())->method('setCreatedAtNow');
        $tokenMock->expects($this->once())->method('setExpiresIn')->with(30);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(RememberToken::class)
            ->willReturn($tokenMock);

        $result = $this->manager->createToken($userId);

        $this->assertArrayHasKey('series', $result);
        $this->assertArrayHasKey('token', $result);
        // encryptedKey should remain null when no encryption key is provided
        $this->assertNull($tokenMock->encryptedKey ?? null);
    }

    public function testValidateAndRotateTokenDecryptsAndReEncryptsKey(): void
    {
        // Create a real token and encryption key for this test
        $originalToken = bin2hex(random_bytes(32));
        $encryptionKey = 'test-encryption-key-12345678';

        // Manually encrypt the key using the same method
        $key = hash('sha256', $originalToken, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($encryptionKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $encryptedKey = $iv . $encrypted;

        $row = [
            'id' => 1,
            'user_id' => 42,
            'series' => 'test-series',
            'token_hash' => password_hash($originalToken, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'last_used_at' => null,
            'user_agent' => null,
            'ip_address' => null,
            'encrypted_key' => $encryptedKey,
        ];

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($row);

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->encryptedKey = $encryptedKey;
        $tokenMock->method('isExpired')->willReturn(false);
        $tokenMock->expects($this->once())->method('touch');
        $tokenMock->expects($this->once())->method('setExpiresIn')->with(30);
        $tokenMock->expects($this->once())->method('save');

        // Mock password_verify to return true for our token
        $tokenMock->tokenHash = password_hash($originalToken, PASSWORD_DEFAULT);

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', $originalToken);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['userId']);
        $this->assertArrayHasKey('newToken', $result);
        $this->assertArrayHasKey('encryptionKey', $result);
        $this->assertEquals($encryptionKey, $result['encryptionKey']);
    }

    public function testCreateTokenStoresKeyedHashNotBcrypt(): void
    {
        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->createToken(42);

        $this->assertSame(
            RememberTokenManager::hashToken($result['token']),
            $tokenMock->tokenHash
        );
        $this->assertStringStartsNotWith('$', $tokenMock->tokenHash);
        $this->assertSame(64, strlen($tokenMock->tokenHash));
    }

    public function testStoredHashDoesNotRevealTheEncryptionKeyDerivedFromTheToken(): void
    {
        // Must differ from encryptWithToken()'s AES key, hash('sha256', $token, true),
        // or reading this table would be enough to decrypt every encrypted_key.
        $token = bin2hex(random_bytes(32));

        $this->assertNotSame(hash('sha256', $token), RememberTokenManager::hashToken($token));
    }

    public function testValidateAndRotateTokenAcceptsLegacyBcryptHashAndRewritesIt(): void
    {
        $originalToken = bin2hex(random_bytes(32));
        $legacyHash = password_hash($originalToken, PASSWORD_BCRYPT, ['cost' => 4]);

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($this->tokenRow($legacyHash));

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->encryptedKey = null;
        $tokenMock->tokenHash = $legacyHash;
        $tokenMock->method('isExpired')->willReturn(false);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', $originalToken);

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['userId']);
        // The row is migrated to the current scheme by the rotation itself.
        $this->assertSame(RememberTokenManager::hashToken($result['newToken']), $tokenMock->tokenHash);
    }

    public function testValidateAndRotateTokenAcceptsCurrentSchemeHash(): void
    {
        $originalToken = bin2hex(random_bytes(32));

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($this->tokenRow(RememberTokenManager::hashToken($originalToken)));

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->encryptedKey = null;
        $tokenMock->tokenHash = RememberTokenManager::hashToken($originalToken);
        $tokenMock->method('isExpired')->willReturn(false);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', $originalToken);

        $this->assertNotNull($result);
        $this->assertSame(RememberTokenManager::hashToken($result['newToken']), $tokenMock->tokenHash);
    }

    public function testValidateAndRotateTokenRevokesEverythingForAWrongToken(): void
    {
        $storedHash = RememberTokenManager::hashToken(bin2hex(random_bytes(32)));

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($this->tokenRow($storedHash));

        // A valid series with the wrong token means the cookie was stolen.
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE FROM remember_tokens WHERE user_id'), 42);

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->tokenHash = $storedHash;
        $tokenMock->method('isExpired')->willReturn(false);
        $tokenMock->expects($this->never())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', bin2hex(random_bytes(32)));

        $this->assertNull($result);
    }

    public function testValidateAndRotateTokenRejectsALegacyHashForTheWrongToken(): void
    {
        $storedHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 4]);

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($this->tokenRow($storedHash));

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE FROM remember_tokens WHERE user_id'), 42);

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->tokenHash = $storedHash;
        $tokenMock->method('isExpired')->willReturn(false);
        $tokenMock->expects($this->never())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', bin2hex(random_bytes(32)));

        $this->assertNull($result);
    }

    public function testValidateAndRotateTokenCompletesWellUnderFiveMilliseconds(): void
    {
        // Two bcrypt runs would have cost ~450 ms here.
        $originalToken = bin2hex(random_bytes(32));

        $this->dbMock->method('queryFirstRow')
            ->willReturn($this->tokenRow(RememberTokenManager::hashToken($originalToken)));

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->encryptedKey = null;
        $tokenMock->tokenHash = RememberTokenManager::hashToken($originalToken);
        $tokenMock->method('isExpired')->willReturn(false);

        $this->entityFactoryMock->method('create')->willReturn($tokenMock);

        // Best of five, so a scheduling hiccup on a shared runner cannot redden this;
        // one bcrypt pair could not come in under the bound on any of the five.
        $bestMs = INF;
        for ($run = 0; $run < 5; $run++) {
            $tokenMock->tokenHash = RememberTokenManager::hashToken($originalToken);

            $startedAt = hrtime(true);
            $result = $this->manager->validateAndRotateToken('test-series', $originalToken);
            $bestMs = min($bestMs, (hrtime(true) - $startedAt) / 1_000_000);

            $this->assertNotNull($result);
        }

        $this->assertLessThan(5.0, $bestMs, sprintf('Validation took %.3f ms', $bestMs));
    }

    /**
     * Decrypts what encryptWithToken() wrote, to check a stored encrypted_key.
     *
     * @param string $encryptedData The stored value, IV prepended.
     * @param string $token The raw token the key was derived from.
     * @return string|false The decrypted data, or false if it does not decrypt.
     */
    private function decryptWithToken(string $encryptedData, string $token): string|false
    {
        return openssl_decrypt(
            substr($encryptedData, 16),
            'aes-256-cbc',
            hash('sha256', $token, true),
            OPENSSL_RAW_DATA,
            substr($encryptedData, 0, 16)
        );
    }

    /**
     * Builds a remember_tokens row that is valid apart from the hash under test.
     *
     * @param string $tokenHash The stored token hash.
     * @return array<string, mixed> The row as queryFirstRow() would return it.
     */
    private function tokenRow(string $tokenHash): array
    {
        return [
            'id' => 1,
            'user_id' => 42,
            'series' => 'test-series',
            'token_hash' => $tokenHash,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'last_used_at' => null,
            'user_agent' => null,
            'ip_address' => null,
            'encrypted_key' => null,
        ];
    }

    public function testValidateAndRotateTokenReturnsNullEncryptionKeyWhenNotStored(): void
    {
        $originalToken = bin2hex(random_bytes(32));

        $row = [
            'id' => 1,
            'user_id' => 42,
            'series' => 'test-series',
            'token_hash' => password_hash($originalToken, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'last_used_at' => null,
            'user_agent' => null,
            'ip_address' => null,
            'encrypted_key' => null,
        ];

        $this->dbMock->expects($this->once())
            ->method('queryFirstRow')
            ->willReturn($row);

        $tokenMock = $this->createMock(RememberToken::class);
        $tokenMock->userId = 42;
        $tokenMock->encryptedKey = null;
        $tokenMock->method('isExpired')->willReturn(false);
        $tokenMock->tokenHash = password_hash($originalToken, PASSWORD_DEFAULT);
        $tokenMock->expects($this->once())->method('touch');
        $tokenMock->expects($this->once())->method('setExpiresIn')->with(30);
        $tokenMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($tokenMock);

        $result = $this->manager->validateAndRotateToken('test-series', $originalToken);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('encryptionKey', $result);
        $this->assertNull($result['encryptionKey']);
    }
}
