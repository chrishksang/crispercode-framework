<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\EmailVerificationToken;

/**
 * Manager for email verification tokens.
 *
 * Handles secure token creation, validation, and cleanup.
 * Uses selector/validator pattern to prevent timing attacks.
 *
 * @extends EntityManagerBase<EmailVerificationToken>
 * @package CrisperCode\EntityManager
 */
#[EntityManagerAttribute(entityClass: EmailVerificationToken::class)]
class EmailVerificationTokenManager extends EntityManagerBase implements EntityManagerInterface
{
    /**
     * Default token expiration in hours.
     */
    public const DEFAULT_EXPIRY_HOURS = 24;

    /**
     * Creates a new email verification token for a user.
     *
     * Deletes any existing tokens for the user before creating a new one.
     *
     * @param int $userId The user ID.
     * @param int|null $expiryHours Hours until expiration (default 24).
     * @return array{selector: string, validator: string} Token data for URL.
     */
    public function createToken(int $userId, ?int $expiryHours = null): array
    {
        // Delete any existing tokens for this user
        $this->deleteTokensForUser($userId);

        // Generate cryptographically secure random values
        // Selector: 16 bytes = 32 hex chars (used for DB lookup)
        // Validator: 32 bytes = 64 hex chars (used for verification)
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        /** @var EmailVerificationToken $token */
        $token = $this->entityFactory->create(EmailVerificationToken::class);
        $token->userId = $userId;
        $token->selector = $selector;
        $token->validatorHash = password_hash($validator, PASSWORD_DEFAULT);
        $token->setCreatedAtNow();
        $token->setExpiresInHours($expiryHours ?? self::DEFAULT_EXPIRY_HOURS);
        $token->save();

        return [
            'selector' => $selector,
            'validator' => $validator,
        ];
    }

    /**
     * Finds a token by its selector.
     *
     * @param string $selector The selector from the URL.
     * @return EmailVerificationToken|null The token, or null if not found.
     */
    public function findBySelector(string $selector): ?EmailVerificationToken
    {
        $row = $this->db->queryFirstRow(
            "SELECT * FROM email_verification_tokens WHERE selector = %s",
            $selector
        );

        if ($row === null) {
            return null;
        }

        /** @var EmailVerificationToken */
        return $this->loadFromValues($row);
    }

    /**
     * Validates a token and returns the user ID if valid.
     *
     * Does NOT mark the token as used - caller should do this after
     * successfully completing the verification process.
     *
     * @param string $selector The selector from the URL.
     * @param string $validator The validator from the URL.
     * @return EmailVerificationToken|null The valid token, or null if invalid.
     */
    public function validateToken(string $selector, string $validator): ?EmailVerificationToken
    {
        $token = $this->findBySelector($selector);

        if (!$token instanceof \CrisperCode\Entity\EmailVerificationToken) {
            return null;
        }

        // Check if token is still valid (not expired, not used)
        if (!$token->isValid()) {
            return null;
        }

        // Verify the validator hash
        if (!password_verify($validator, $token->validatorHash)) {
            return null;
        }

        return $token;
    }

    /**
     * Marks a token as used.
     *
     * @param EmailVerificationToken $token The token to mark as used.
     */
    public function markAsUsed(EmailVerificationToken $token): void
    {
        $token->markAsUsed();
        $token->save();
    }

    /**
     * Deletes all tokens for a user.
     *
     * @param int $userId The user ID.
     */
    public function deleteTokensForUser(int $userId): void
    {
        $this->db->query(
            "DELETE FROM email_verification_tokens WHERE user_id = %i",
            $userId
        );
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
            "DELETE FROM email_verification_tokens WHERE expires_at < %s",
            $now
        );

        return $this->db->affectedRows();
    }

    /**
     * Checks if a user has a pending (valid, unused) verification token.
     *
     * @param int $userId The user ID.
     * @return bool True if user has a pending token.
     */
    public function hasPendingToken(int $userId): bool
    {
        $now = date('Y-m-d H:i:s');

        $count = $this->db->queryFirstField(
            "SELECT COUNT(*) FROM email_verification_tokens
             WHERE user_id = %i AND expires_at > %s AND used_at IS NULL",
            $userId,
            $now
        );

        return (int) $count > 0;
    }

    /**
     * Formats a verification URL from selector and validator.
     *
     * @param string $baseUrl The base URL of the application.
     * @param string $selector The selector.
     * @param string $validator The validator.
     * @return string The complete verification URL.
     */
    public static function formatVerificationUrl(
        string $baseUrl,
        string $selector,
        string $validator
    ): string {
        return rtrim($baseUrl, '/') . '/verify-email/confirm?'
            . http_build_query([
                'selector' => $selector,
                'token' => $validator,
            ]);
    }
}
