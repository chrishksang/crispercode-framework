<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;

/**
 * User entity for authentication.
 *
 * Supports email/password authentication with provisions for future OAuth integration.
 *
 * @package CrisperCode\Entity
 */
#[Index(columns: ['email'], name: 'idx_email', unique: true)]
#[Index(columns: ['google_id'], name: 'idx_google_id')]
class User extends EntityBase
{
    public const TABLE_NAME = 'users';

    /**
     * User's email address (unique identifier for login).
     */
    #[Column(type: 'VARCHAR', length: 255)]
    public string $email;

    /**
     * Hashed password. Nullable for OAuth-only users.
     */
    #[Column(type: 'VARCHAR', length: 255, nullable: true)]
    public ?string $passwordHash = null;

    /**
     * Google account ID for OAuth integration.
     */
    #[Column(type: 'VARCHAR', length: 255, nullable: true)]
    public ?string $googleId = null;

    /**
     * Authentication provider: 'email' or 'google'.
     */
    #[Column(type: 'VARCHAR', length: 50, default: 'email')]
    public string $authProvider = 'email';

    /**
     * Timestamp when the user was created.
     */
    #[Column(type: 'DATETIME')]
    public string $createdAt;

    /**
     * Timestamp when the user was last updated.
     */
    #[Column(type: 'DATETIME', nullable: true)]
    public ?string $updatedAt = null;

    /**
     * Whether the user's email has been verified.
     */
    #[Column(type: 'BOOLEAN', default: '0')]
    public bool $emailVerified = false;

    /**
     * Timestamp when the email was verified.
     */
    #[Column(type: 'DATETIME', nullable: true)]
    public ?string $emailVerifiedAt = null;

    /**
     * Marks the user's email as verified.
     */
    public function markEmailVerified(): void
    {
        $this->emailVerified = true;
        $this->emailVerifiedAt = date('Y-m-d H:i:s');
    }

    /**
     * Sets the creation timestamp if not already set.
     */
    public function setCreatedAtIfNew(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = date('Y-m-d H:i:s');
        }
    }

    /**
     * Updates the updated_at timestamp.
     */
    public function touch(): void
    {
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    /**
     * Persists the entity, automatically managing timestamps.
     */
    public function save(): int
    {
        $this->setCreatedAtIfNew();
        $this->touch();
        return parent::save();
    }
}
