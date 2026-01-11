<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\RememberToken;

/**
 * Manager for Remember Me tokens.
 *
 * Handles secure token creation, validation with rotation, and session management.
 *
 * @extends EntityManagerBase<RememberToken>
 * @package CrisperCode\EntityManager
 */
#[EntityManagerAttribute(entityClass: RememberToken::class)]
class RememberTokenManager extends EntityManagerBase implements EntityManagerInterface
{
    /**
     * Cookie name for the remember me token.
     */
    public const COOKIE_NAME = 'remember_me';

    /**
     * Token expiration in days.
     */
    private const TOKEN_EXPIRY_DAYS = 30;

    /**
     * Creates a new remember me token for a user.
     *
     * @param int $userId The user ID.
     * @param string|null $userAgent Browser user agent.
     * @param string|null $ipAddress Client IP address.
     * @param string|null $encryptionKey User's encryption key to encrypt and store.
     * @return array{series: string, token: string, expires: int} Token data for cookie.
     */
    public function createToken(
        int $userId,
        ?string $userAgent = null,
        ?string $ipAddress = null,
        ?string $encryptionKey = null
    ): array {
        // Generate cryptographically secure random values
        $series = bin2hex(random_bytes(32));
        $token = bin2hex(random_bytes(32));

        /** @var RememberToken $rememberToken */
        $rememberToken = $this->entityFactory->create(RememberToken::class);
        $rememberToken->userId = $userId;
        $rememberToken->series = $series;
        $rememberToken->tokenHash = password_hash($token, PASSWORD_DEFAULT);
        $rememberToken->setCreatedAtNow();
        $rememberToken->setExpiresIn(self::TOKEN_EXPIRY_DAYS);
        $rememberToken->userAgent = $userAgent !== null ? substr($userAgent, 0, 500) : null;
        $rememberToken->ipAddress = $ipAddress;

        // Encrypt and store the encryption key if provided
        if ($encryptionKey !== null) {
            $rememberToken->encryptedKey = $this->encryptWithToken($encryptionKey, $token);
        }

        $rememberToken->save();

        return [
            'series' => $series,
            'token' => $token,
            'expires' => time() + (self::TOKEN_EXPIRY_DAYS * 86400),
        ];
    }

    /**
     * Validates a remember me token and rotates it.
     *
     * @param string $series The series identifier from the cookie.
     * @param string $token The token value from the cookie.
     * @param string|null $userAgent Current user agent (for updating).
     * @param string|null $ipAddress Current IP (for updating).
     * @return array{userId: int, newToken: string, encryptionKey: string|null}|null
     *   User ID, new token, and encryption key, or null if invalid.
     */
    public function validateAndRotateToken(
        string $series,
        string $token,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): ?array {
        // Find token by series
        $row = $this->db->queryFirstRow(
            "SELECT * FROM remember_tokens WHERE series = %s",
            $series
        );

        if ($row === null) {
            return null;
        }

        /** @var RememberToken $rememberToken */
        $rememberToken = $this->loadFromValues($row);

        // Check if expired
        if ($rememberToken->isExpired()) {
            $this->revokeToken($series);
            return null;
        }

        // Verify token hash
        if (!password_verify($token, $rememberToken->tokenHash)) {
            // Token mismatch with valid series = possible theft!
            // Revoke all tokens for this user as a security measure
            $this->revokeAllForUser($rememberToken->userId);
            return null;
        }

        // Decrypt the encryption key if present
        $encryptionKey = null;
        if ($rememberToken->encryptedKey !== null) {
            $encryptionKey = $this->decryptWithToken($rememberToken->encryptedKey, $token);
        }

        // Token is valid - rotate it
        $newToken = bin2hex(random_bytes(32));
        $rememberToken->tokenHash = password_hash($newToken, PASSWORD_DEFAULT);
        $rememberToken->touch();
        $rememberToken->setExpiresIn(self::TOKEN_EXPIRY_DAYS);

        // Re-encrypt the encryption key with the new token
        if ($encryptionKey !== null) {
            $rememberToken->encryptedKey = $this->encryptWithToken($encryptionKey, $newToken);
        }

        if ($userAgent !== null) {
            $rememberToken->userAgent = substr($userAgent, 0, 500);
        }
        if ($ipAddress !== null) {
            $rememberToken->ipAddress = $ipAddress;
        }

        $rememberToken->save();

        return [
            'userId' => $rememberToken->userId,
            'newToken' => $newToken,
            'encryptionKey' => $encryptionKey,
        ];
    }

    /**
     * Revokes a single remember me token by series.
     *
     * @param string $series The series identifier.
     */
    public function revokeToken(string $series): void
    {
        $this->db->query(
            "DELETE FROM remember_tokens WHERE series = %s",
            $series
        );
    }

    /**
     * Revokes all remember me tokens for a user.
     *
     * Use this for "logout everywhere" functionality or after password change.
     *
     * @param int $userId The user ID.
     */
    public function revokeAllForUser(int $userId): void
    {
        $this->db->query(
            "DELETE FROM remember_tokens WHERE user_id = %i",
            $userId
        );
    }

    /**
     * Gets all active sessions for a user.
     *
     * @param int $userId The user ID.
     * @return array<RememberToken> Active remember tokens.
     */
    public function getActiveSessionsForUser(int $userId): array
    {
        $now = date('Y-m-d H:i:s');

        $rows = $this->db->query(
            "SELECT * FROM remember_tokens 
             WHERE user_id = %i AND expires_at > %s 
             ORDER BY last_used_at DESC",
            $userId,
            $now
        );

        return array_map(function ($row) {
            return $this->loadFromValues($row);
        }, $rows);
    }

    /**
     * Removes all expired tokens from the database.
     *
     * @return int Number of tokens removed.
     */
    public function cleanupExpired(): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "DELETE FROM remember_tokens WHERE expires_at < %s",
            $now
        );

        return $this->db->affectedRows();
    }

    /**
     * Formats a cookie value from series and token.
     *
     * @param string $series The series identifier.
     * @param string $token The token value.
     * @return string The formatted cookie value.
     */
    public static function formatCookieValue(string $series, string $token): string
    {
        return base64_encode($series . ':' . $token);
    }

    /**
     * Parses a cookie value into series and token.
     *
     * @param string $cookieValue The raw cookie value.
     * @return array{series: string, token: string}|null Parsed values or null if invalid.
     */
    public static function parseCookieValue(string $cookieValue): ?array
    {
        $decoded = base64_decode($cookieValue, true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'series' => $parts[0],
            'token' => $parts[1],
        ];
    }

    /**
     * Encrypts data using a token-derived key.
     *
     * Uses AES-256-CBC with a random IV prepended to the ciphertext.
     * The token is hashed via SHA-256 to derive the encryption key.
     *
     * @param string $data The data to encrypt.
     * @param string $token The raw token to derive the key from.
     * @return string The encrypted data with IV prepended.
     */
    private function encryptWithToken(string $data, string $token): string
    {
        $key = hash('sha256', $token, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return $iv . $encrypted;
    }

    /**
     * Decrypts data using a token-derived key.
     *
     * Expects data encrypted with encryptWithToken() (IV prepended).
     * The token is hashed via SHA-256 to derive the decryption key.
     *
     * @param string $encryptedData The encrypted data with IV prepended.
     * @param string $token The raw token to derive the key from.
     * @return string|null The decrypted data, or null if decryption fails.
     */
    private function decryptWithToken(string $encryptedData, string $token): ?string
    {
        // Minimum size: 16 bytes (IV) + at least 1 byte of encrypted data
        $minEncryptedSize = 17;
        if (strlen($encryptedData) < $minEncryptedSize) {
            return null;
        }

        $key = hash('sha256', $token, true);
        $iv = substr($encryptedData, 0, 16);
        $encrypted = substr($encryptedData, 16);

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? null : $decrypted;
    }
}
