<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\RememberToken;
use CrisperCode\EntityFactory;
use MeekroDB;
use Psr\Log\LoggerInterface;

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
     * Key for the token hash HMAC.
     *
     * Not secret - it is domain separation, keeping this hash independent of the
     * AES key encryptWithToken() derives from the same token via
     * hash('sha256', $token, true). Without it a leaked token_hash would double
     * as that key. Changing it invalidates every stored hash, which reads as
     * theft and revokes the tokens it is checked against.
     */
    private const TOKEN_HASH_KEY = 'crispercode/remember-token-hash/v1';

    /**
     * Optional logger for verification timing.
     */
    private ?LoggerInterface $logger;

    /**
     * RememberTokenManager constructor.
     *
     * @param MeekroDB $db Database connection.
     * @param EntityFactory $entityFactory Entity factory instance.
     * @param LoggerInterface|null $logger Optional logger for verification timing.
     */
    public function __construct(MeekroDB $db, EntityFactory $entityFactory, ?LoggerInterface $logger = null)
    {
        parent::__construct($db, $entityFactory);

        $this->logger = $logger;
    }

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
        $rememberToken->tokenHash = self::hashToken($token);
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
        if (!$this->verifyTokenHash($token, $rememberToken->tokenHash)) {
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
        // Rotation always writes the current scheme, so a legacy row migrates on first use.
        $rememberToken->tokenHash = self::hashToken($newToken);
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
     * Hashes a remember me token for storage.
     *
     * Deliberately fast. The token is 32 bytes from random_bytes(), not a
     * guessable secret, so bcrypt's cost bought no security here - only latency
     * on every remembered-session request. A keyed SHA-256 compared with
     * hash_equals() gives the same protection against a stolen database.
     *
     * @param string $token The raw token from the cookie.
     * @return string The hex-encoded hash to store in token_hash.
     */
    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, self::TOKEN_HASH_KEY);
    }

    /**
     * Verifies a presented token against a stored hash, accepting either scheme.
     *
     * A password_hash() digest always starts with "$" (e.g. "$2y$..."); a current
     * hash is 64 hex characters and never does. Rotation rewrites the row with
     * the current scheme, so this legacy branch can go once every token issued
     * before the switch has expired (TOKEN_EXPIRY_DAYS).
     *
     * @param string $token The raw token from the cookie.
     * @param string $storedHash The hash stored in token_hash.
     * @return bool True if the token matches.
     */
    private function verifyTokenHash(string $token, string $storedHash): bool
    {
        $legacy = str_starts_with($storedHash, '$');

        $startedAt = hrtime(true);
        $valid = $legacy
            ? password_verify($token, $storedHash)
            : hash_equals($storedHash, self::hashToken($token));
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        // Logged because the cost is otherwise invisible: it sits between the SELECT and the UPDATE.
        $this->logger?->debug('Remember token hash verified', [
            'scheme' => $legacy ? 'password_hash' : 'hmac-sha256',
            'valid' => $valid,
            'duration_ms' => round($elapsedMs, 3),
        ]);

        return $valid;
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
