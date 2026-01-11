<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\LoginAttempt;

/**
 * Manager for login attempt tracking and rate limiting.
 *
 * Provides methods to record attempts, check rate limits, and cleanup old records.
 *
 * @extends EntityManagerBase<LoginAttempt>
 * @package CrisperCode\EntityManager
 */
#[EntityManagerAttribute(entityClass: LoginAttempt::class)]
class LoginAttemptManager extends EntityManagerBase implements EntityManagerInterface
{
    /**
     * Rate limiting thresholds: [max_attempts => lockout_minutes]
     */
    private const LOCKOUT_THRESHOLDS = [
        5 => 1,    // After 5 failures: 1 minute lockout
        10 => 5,   // After 10 failures: 5 minute lockout
        20 => 30,  // After 20 failures: 30 minute lockout
    ];

    /**
     * Records a login attempt.
     *
     * @param string $email The email address attempted.
     * @param string $ipAddress The client IP address.
     * @param bool $successful Whether the attempt was successful.
     * @return LoginAttempt The recorded attempt.
     */
    public function recordAttempt(string $email, string $ipAddress, bool $successful): LoginAttempt
    {
        /** @var LoginAttempt $attempt */
        $attempt = $this->entityFactory->create(LoginAttempt::class);
        $attempt->email = strtolower(trim($email));
        $attempt->ipAddress = $ipAddress;
        $attempt->successful = $successful ? 1 : 0;
        $attempt->setAttemptedAtNow();
        $attempt->save();

        return $attempt;
    }

    /**
     * Gets the count of recent failed login attempts.
     *
     * @param string $email The email address to check.
     * @param string $ipAddress The IP address to check.
     * @param int $minutes How far back to look (in minutes).
     * @return int The number of failed attempts.
     */
    public function getRecentFailedCount(string $email, string $ipAddress, int $minutes = 60): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));
        $email = strtolower(trim($email));

        return (int) $this->db->queryFirstField(
            "SELECT COUNT(*) FROM login_attempts 
             WHERE (email = %s OR ip_address = %s) 
             AND successful = 0 
             AND attempted_at > %s",
            $email,
            $ipAddress,
            $cutoff
        );
    }

    /**
     * Checks if further login attempts should be blocked.
     *
     * Returns the number of seconds until the lockout expires, or 0 if not locked out.
     *
     * @param string $email The email address to check.
     * @param string $ipAddress The IP address to check.
     * @return int Seconds remaining in lockout, or 0 if not locked.
     */
    public function getLockoutSecondsRemaining(string $email, string $ipAddress): int
    {
        $email = strtolower(trim($email));

        // Check each threshold from highest to lowest
        foreach (array_reverse(self::LOCKOUT_THRESHOLDS, true) as $maxAttempts => $lockoutMinutes) {
            $failedCount = $this->getRecentFailedCount($email, $ipAddress, $lockoutMinutes);

            if ($failedCount >= $maxAttempts) {
                // Get the most recent failed attempt
                $lastAttempt = $this->db->queryFirstField(
                    "SELECT attempted_at FROM login_attempts 
                     WHERE (email = %s OR ip_address = %s) 
                     AND successful = 0 
                     ORDER BY attempted_at DESC 
                     LIMIT 1",
                    $email,
                    $ipAddress
                );

                if ($lastAttempt !== null) {
                    $lastAttemptTime = strtotime($lastAttempt);
                    $unlockTime = $lastAttemptTime + ($lockoutMinutes * 60);
                    $remaining = $unlockTime - time();

                    if ($remaining > 0) {
                        return $remaining;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Checks if login is currently allowed.
     *
     * @param string $email The email address to check.
     * @param string $ipAddress The IP address to check.
     * @return bool True if login is allowed.
     */
    public function isLoginAllowed(string $email, string $ipAddress): bool
    {
        return $this->getLockoutSecondsRemaining($email, $ipAddress) === 0;
    }

    /**
     * Gets the count of recent registration attempts from an IP.
     *
     * @param string $ipAddress The IP address to check.
     * @param int $minutes How far back to look (in minutes).
     * @return int The number of registration attempts.
     */
    public function getRecentRegistrationCount(string $ipAddress, int $minutes = 60): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));

        return (int) $this->db->queryFirstField(
            "SELECT COUNT(*) FROM login_attempts 
             WHERE ip_address = %s 
             AND attempted_at > %s",
            $ipAddress,
            $cutoff
        );
    }

    /**
     * Checks if registration is allowed from this IP address.
     *
     * Rate limit: Maximum 5 registration attempts per hour per IP.
     *
     * @param string $ipAddress The IP address to check.
     * @return bool True if registration is allowed.
     */
    public function isRegistrationAllowed(string $ipAddress): bool
    {
        return $this->getRecentRegistrationCount($ipAddress, 60) < 5;
    }

    /**
     * Clears failed attempts after a successful login.
     *
     * This resets the rate limiting counter for the email/IP combination.
     *
     * @param string $email The email address.
     * @param string $ipAddress The IP address.
     */
    public function clearFailedAttempts(string $email, string $ipAddress): void
    {
        $email = strtolower(trim($email));

        $this->db->query(
            "DELETE FROM login_attempts 
             WHERE (email = %s OR ip_address = %s) 
             AND successful = 0",
            $email,
            $ipAddress
        );
    }

    /**
     * Removes old login attempts from the database.
     *
     * @param int $olderThanHours Remove attempts older than this many hours.
     * @return int Number of records deleted.
     */
    public function cleanupOldAttempts(int $olderThanHours = 24): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($olderThanHours * 3600));

        $this->db->query(
            "DELETE FROM login_attempts WHERE attempted_at < %s",
            $cutoff
        );

        return $this->db->affectedRows();
    }
}
