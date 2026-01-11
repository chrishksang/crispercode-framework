<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;

/**
 * Entity to track login attempts for rate limiting.
 *
 * Records both successful and failed login attempts to enable:
 * - Rate limiting after too many failures
 * - Security audit trail
 * - IP-based and email-based throttling
 *
 * @package CrisperCode\Entity
 */
#[Index(columns: ['email', 'ip_address'], name: 'idx_login_attempt_email_ip')]
#[Index(columns: ['attempted_at'], name: 'idx_login_attempt_time')]
class LoginAttempt extends EntityBase
{
    public const TABLE_NAME = 'login_attempts';

    /**
     * Email address attempted.
     */
    #[Column(type: 'VARCHAR', length: 255)]
    public string $email;

    /**
     * Client IP address.
     */
    #[Column(type: 'VARCHAR', length: 45)]
    public string $ipAddress;

    /**
     * Whether the attempt was successful.
     */
    #[Column(type: 'TINYINT', length: 1, default: 0)]
    public int $successful = 0;

    /**
     * Timestamp of the attempt.
     */
    #[Column(type: 'DATETIME')]
    public string $attemptedAt;

    /**
     * Sets the attempt timestamp to current time.
     */
    public function setAttemptedAtNow(): void
    {
        $this->attemptedAt = date('Y-m-d H:i:s');
    }
}
