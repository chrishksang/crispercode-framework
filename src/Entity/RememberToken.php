<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;

/**
 * Entity for persistent "Remember Me" login tokens.
 *
 * Implements secure token storage with:
 * - Series identifier to detect token theft
 * - Token rotation on each use
 * - User agent/IP tracking for session display
 *
 * @package CrisperCode\Entity
 */
#[Index(columns: ['user_id'], name: 'idx_remember_token_user')]
#[Index(columns: ['series'], name: 'idx_remember_token_series', unique: true)]
#[Index(columns: ['expires_at'], name: 'idx_remember_token_expires')]
class RememberToken extends EntityBase
{
    public const TABLE_NAME = 'remember_tokens';

    /**
     * ID of the user this token belongs to.
     */
    #[Column(type: 'INT')]
    public int $userId;

    /**
     * Random series identifier (public, stored in cookie).
     * Used to detect token theft - if series is valid but token is not,
     * it indicates the token was stolen and used.
     */
    #[Column(type: 'VARCHAR', length: 64)]
    public string $series;

    /**
     * Hashed token value (private, validated against cookie).
     */
    #[Column(type: 'VARCHAR', length: 255)]
    public string $tokenHash;

    /**
     * Encrypted user encryption key (encrypted with token-derived key).
     */
    #[Column(type: 'VARBINARY', length: 256, nullable: true)]
    public ?string $encryptedKey = null;

    /**
     * When this token was created.
     */
    #[Column(type: 'DATETIME')]
    public string $createdAt;

    /**
     * When this token expires.
     */
    #[Column(type: 'DATETIME')]
    public string $expiresAt;

    /**
     * When this token was last used for authentication.
     */
    #[Column(type: 'DATETIME', nullable: true)]
    public ?string $lastUsedAt = null;

    /**
     * User agent string for session display.
     */
    #[Column(type: 'VARCHAR', length: 500, nullable: true)]
    public ?string $userAgent = null;

    /**
     * IP address for session display.
     */
    #[Column(type: 'VARCHAR', length: 45, nullable: true)]
    public ?string $ipAddress = null;

    /**
     * Checks if this token has expired.
     */
    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    /**
     * Sets the creation timestamp to now.
     */
    public function setCreatedAtNow(): void
    {
        $this->createdAt = date('Y-m-d H:i:s');
    }

    /**
     * Sets the expiration timestamp.
     *
     * @param int $days Number of days until expiration.
     */
    public function setExpiresIn(int $days = 30): void
    {
        $this->expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));
    }

    /**
     * Updates the last used timestamp.
     */
    public function touch(): void
    {
        $this->lastUsedAt = date('Y-m-d H:i:s');
    }
}
