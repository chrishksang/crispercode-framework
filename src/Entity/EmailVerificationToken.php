<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;

/**
 * Entity for email verification tokens.
 *
 * Implements secure token storage with:
 * - Selector for database lookup (prevents timing attacks)
 * - Hashed validator (never store plain tokens)
 * - Expiration tracking
 * - Single-use enforcement via usedAt timestamp
 *
 * @package CrisperCode\Entity
 */
#[Index(columns: ['user_id'], name: 'idx_email_verification_user')]
#[Index(columns: ['selector'], name: 'idx_email_verification_selector', unique: true)]
#[Index(columns: ['expires_at'], name: 'idx_email_verification_expires')]
class EmailVerificationToken extends EntityBase
{
    public const TABLE_NAME = 'email_verification_tokens';

    /**
     * ID of the user this token belongs to.
     */
    #[Column(type: 'INT')]
    public int $userId;

    /**
     * Random selector for database lookup (public, used in URL).
     * Prevents timing attacks by separating lookup from validation.
     */
    #[Column(type: 'VARCHAR', length: 32)]
    public string $selector;

    /**
     * Hashed validator value (private, validated against URL parameter).
     */
    #[Column(type: 'VARCHAR', length: 255)]
    public string $validatorHash;

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
     * When this token was used. Null if not yet used.
     */
    #[Column(type: 'DATETIME', nullable: true)]
    public ?string $usedAt = null;

    /**
     * Checks if this token has expired.
     */
    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    /**
     * Checks if this token has been used.
     */
    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    /**
     * Checks if this token is valid (not expired and not used).
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
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
     * @param int $hours Number of hours until expiration.
     */
    public function setExpiresInHours(int $hours = 24): void
    {
        $this->expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));
    }

    /**
     * Marks the token as used.
     */
    public function markAsUsed(): void
    {
        $this->usedAt = date('Y-m-d H:i:s');
    }
}
