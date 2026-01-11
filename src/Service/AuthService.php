<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use CrisperCode\Entity\RememberToken;
use CrisperCode\Entity\User;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\LoginAttemptManager;
use CrisperCode\EntityManager\RememberTokenManager;
use Psr\Log\LoggerInterface;

/**
 * Authentication service for managing user sessions.
 *
 * Handles login, logout, password hashing, session management,
 * rate limiting, remember me tokens, and security logging.
 *
 * @package CrisperCode\Service
 */
class AuthService
{
    private const SESSION_USER_ID_KEY = 'auth_user_id';
    private const SESSION_CREATED_AT_KEY = 'auth_session_created_at';
    private const SESSION_SERIES_KEY = 'auth_remember_series';
    private const SESSION_ENCRYPTION_KEY = 'auth_encryption_key';

    /**
     * Session timeout in seconds (24 hours).
     */
    private const SESSION_TIMEOUT_SECONDS = 86400;

    private EntityFactory $entityFactory;
    private LoginAttemptManager $loginAttemptManager;
    private RememberTokenManager $rememberTokenManager;
    private LoggerInterface $logger;

    /**
     * Cached user instance for the current request.
     */
    private ?User $currentUser = null;

    /**
     * Whether the user has been loaded for this request.
     */
    private bool $userLoaded = false;

    public function __construct(
        EntityFactory $entityFactory,
        LoginAttemptManager $loginAttemptManager,
        RememberTokenManager $rememberTokenManager,
        LoggerInterface $logger
    ) {
        $this->entityFactory = $entityFactory;
        $this->loginAttemptManager = $loginAttemptManager;
        $this->rememberTokenManager = $rememberTokenManager;
        $this->logger = $logger;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Attempts to authenticate a user with email and password.
     *
     * Includes rate limiting, attempt logging, and optional remember me.
     *
     * @param string $email The email address.
     * @param string $password The password.
     * @param string $ipAddress The client IP address.
     * @param bool $rememberMe Whether to create a remember me token.
     * @param string|null $userAgent The browser user agent.
     * @return array{success: bool, user?: User, error?: string, lockoutSeconds?: int}
     */
    public function attemptLogin(
        string $email,
        string $password,
        string $ipAddress,
        bool $rememberMe = false,
        ?string $userAgent = null
    ): array {
        $email = strtolower(trim($email));

        // Check rate limiting
        $lockoutSeconds = $this->loginAttemptManager->getLockoutSecondsRemaining($email, $ipAddress);
        if ($lockoutSeconds > 0) {
            $this->logger->warning('Login blocked due to rate limiting', [
                'email' => $email,
                'ip' => $ipAddress,
                'lockout_seconds' => $lockoutSeconds,
            ]);

            return [
                'success' => false,
                'error' => 'Too many failed attempts. Please try again later.',
                'lockoutSeconds' => $lockoutSeconds,
            ];
        }

        // Find user
        $user = $this->findUserByEmail($email);

        // Use timing-safe comparison to prevent user enumeration
        // Always hash a dummy password even if user not found
        $dummyHash = '$2y$10$abcdefghijklmnopqrstuv1234567890123456789012345678901234';
        $passwordHash = ($user instanceof \CrisperCode\Entity\User && $user->passwordHash !== null)
            ? $user->passwordHash
            : $dummyHash;

        // Always verify password (even if user not found) to maintain constant time
        $passwordValid = $this->verifyPassword($password, $passwordHash);

        // Check if user exists and password is valid
        if (!$user instanceof \CrisperCode\Entity\User || $user->passwordHash === null || !$passwordValid) {
            $this->loginAttemptManager->recordAttempt($email, $ipAddress, false);
            $this->logger->warning('Login failed: invalid credentials', [
                'email' => $email,
                'ip' => $ipAddress,
            ]);

            return [
                'success' => false,
                'error' => 'Invalid email or password.',
            ];
        }

        // Successful login
        $this->loginAttemptManager->recordAttempt($email, $ipAddress, true);
        $this->loginAttemptManager->clearFailedAttempts($email, $ipAddress);

        // Derive and store encryption key from password
        $encryptionKey = $this->deriveEncryptionKey($password, $user->id);
        $_SESSION[self::SESSION_ENCRYPTION_KEY] = $encryptionKey;

        $this->login($user, $rememberMe, $userAgent, $ipAddress);

        $this->logger->info('Login successful', [
            'user_id' => $user->id,
            'email' => $email,
            'ip' => $ipAddress,
            'remember_me' => $rememberMe,
        ]);

        return [
            'success' => true,
            'user' => $user,
        ];
    }

    /**
     * Logs in a user by storing their ID in the session.
     *
     * @param User $user The user to log in.
     * @param bool $rememberMe Whether to create a remember me token.
     * @param string|null $userAgent The browser user agent.
     * @param string|null $ipAddress The client IP.
     */
    public function login(
        User $user,
        bool $rememberMe = false,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): void {
        $_SESSION[self::SESSION_USER_ID_KEY] = $user->id;
        $_SESSION[self::SESSION_CREATED_AT_KEY] = time();
        $this->currentUser = $user;
        $this->userLoaded = true;

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        // Create remember me token if requested
        if ($rememberMe) {
            $encryptionKey = $_SESSION[self::SESSION_ENCRYPTION_KEY] ?? null;

            $tokenData = $this->rememberTokenManager->createToken(
                $user->id,
                $userAgent,
                $ipAddress,
                $encryptionKey
            );

            $_SESSION[self::SESSION_SERIES_KEY] = $tokenData['series'];

            // Set the cookie
            $cookieValue = RememberTokenManager::formatCookieValue(
                $tokenData['series'],
                $tokenData['token']
            );

            setcookie(
                RememberTokenManager::COOKIE_NAME,
                $cookieValue,
                [
                    'expires' => $tokenData['expires'],
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }
    }

    /**
     * Logs out the current user.
     *
     * @param bool $everywhere If true, revokes all remember me tokens (logout everywhere).
     */
    public function logout(bool $everywhere = false): void
    {
        $userId = $_SESSION[self::SESSION_USER_ID_KEY] ?? null;
        $series = $_SESSION[self::SESSION_SERIES_KEY] ?? null;

        if ($userId !== null) {
            $this->logger->info('User logged out', [
                'user_id' => $userId,
                'everywhere' => $everywhere,
            ]);

            if ($everywhere) {
                $this->rememberTokenManager->revokeAllForUser((int) $userId);
            } elseif ($series !== null) {
                $this->rememberTokenManager->revokeToken($series);
            }
        }

        // Clear session
        unset($_SESSION[self::SESSION_USER_ID_KEY]);
        unset($_SESSION[self::SESSION_CREATED_AT_KEY]);
        unset($_SESSION[self::SESSION_SERIES_KEY]);

        $this->currentUser = null;
        $this->userLoaded = true;

        // Clear remember me cookie
        setcookie(
            RememberTokenManager::COOKIE_NAME,
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Regenerate session ID
        session_regenerate_id(true);
    }

    /**
     * Attempts to authenticate using the remember me cookie.
     *
     * @param string|null $userAgent Current user agent.
     * @param string|null $ipAddress Current IP address.
     * @return bool True if successfully authenticated.
     */
    public function attemptRememberMeLogin(?string $userAgent = null, ?string $ipAddress = null): bool
    {
        $cookieValue = $_COOKIE[RememberTokenManager::COOKIE_NAME] ?? null;
        if ($cookieValue === null) {
            return false;
        }

        $parsed = RememberTokenManager::parseCookieValue($cookieValue);
        if ($parsed === null) {
            return false;
        }

        $result = $this->rememberTokenManager->validateAndRotateToken(
            $parsed['series'],
            $parsed['token'],
            $userAgent,
            $ipAddress
        );

        if ($result === null) {
            // Invalid token - clear the cookie
            setcookie(
                RememberTokenManager::COOKIE_NAME,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
            return false;
        }

        // Load the user
        $user = $this->entityFactory->findById(User::class, $result['userId']);
        if (!$user instanceof \CrisperCode\Entity\EntityInterface) {
            return false;
        }

        // Set up session
        $_SESSION[self::SESSION_USER_ID_KEY] = $user->id;
        $_SESSION[self::SESSION_CREATED_AT_KEY] = time();
        $_SESSION[self::SESSION_SERIES_KEY] = $parsed['series'];

        // Restore encryption key if available
        if ($result['encryptionKey'] !== null) {
            $_SESSION[self::SESSION_ENCRYPTION_KEY] = $result['encryptionKey'];
        }

        $this->currentUser = $user;
        $this->userLoaded = true;

        // Update cookie with rotated token
        $cookieValue = RememberTokenManager::formatCookieValue(
            $parsed['series'],
            $result['newToken']
        );

        setcookie(
            RememberTokenManager::COOKIE_NAME,
            $cookieValue,
            [
                'expires' => time() + (30 * 86400),
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        session_regenerate_id(true);

        $this->logger->info('Remember me login successful', [
            'user_id' => $user->id,
            'ip' => $ipAddress,
        ]);

        return true;
    }

    /**
     * Gets the currently authenticated user.
     *
     * Checks session timeout and returns null if expired.
     *
     * @return User|null The authenticated user, or null if not logged in.
     */
    public function getUser(): ?User
    {
        if ($this->userLoaded) {
            return $this->currentUser;
        }

        $this->userLoaded = true;

        if (!isset($_SESSION[self::SESSION_USER_ID_KEY])) {
            return null;
        }

        // Check session timeout (24 hours)
        $sessionCreatedAt = $_SESSION[self::SESSION_CREATED_AT_KEY] ?? 0;
        if (time() - $sessionCreatedAt > self::SESSION_TIMEOUT_SECONDS) {
            $this->logger->info('Session expired due to timeout', [
                'user_id' => $_SESSION[self::SESSION_USER_ID_KEY],
            ]);

            // Don't call logout() as it would log to the user - just clear session
            unset($_SESSION[self::SESSION_USER_ID_KEY]);
            unset($_SESSION[self::SESSION_CREATED_AT_KEY]);
            return null;
        }

        $userId = (int) $_SESSION[self::SESSION_USER_ID_KEY];
        $this->currentUser = $this->entityFactory->findById(User::class, $userId);

        return $this->currentUser;
    }

    /**
     * Gets active sessions for the current user.
     *
     * @return array<RememberToken> Active remember tokens.
     */
    public function getActiveSessions(): array
    {
        $user = $this->getUser();
        if (!$user instanceof \CrisperCode\Entity\User) {
            return [];
        }

        return $this->rememberTokenManager->getActiveSessionsForUser($user->id);
    }

    /**
     * Gets the series identifier of the current session's remember token.
     *
     * @return string|null The series, or null if not using remember me.
     */
    public function getCurrentSessionSeries(): ?string
    {
        return $_SESSION[self::SESSION_SERIES_KEY] ?? null;
    }

    /**
     * Revokes a specific session by series.
     *
     * @param string $series The series identifier.
     * @return bool True if revoked, false if not allowed.
     */
    public function revokeSession(string $series): bool
    {
        $user = $this->getUser();
        if (!$user instanceof \CrisperCode\Entity\User) {
            return false;
        }

        // Verify this session belongs to the current user
        $sessions = $this->getActiveSessions();
        foreach ($sessions as $session) {
            if ($session->series === $series && $session->userId === $user->id) {
                $this->rememberTokenManager->revokeToken($series);

                $this->logger->info('Session revoked', [
                    'user_id' => $user->id,
                    'series' => $series,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a user is currently logged in.
     *
     * @return bool True if logged in.
     */
    public function isLoggedIn(): bool
    {
        return $this->getUser() instanceof \CrisperCode\Entity\User;
    }

    /**
     * Hashes a password for storage.
     *
     * @param string $password The plain text password.
     * @return string The hashed password.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verifies a password against a hash.
     *
     * @param string $password The plain text password.
     * @param string $hash The stored hash.
     * @return bool True if password matches.
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Finds a user by email address.
     *
     * @param string $email The email to search for.
     * @return User|null The user if found.
     */
    public function findUserByEmail(string $email): ?User
    {
        return $this->entityFactory->findOneBy(User::class, ['email' => strtolower(trim($email))]);
    }

    /**
     * Gets the remaining lockout time for a login attempt.
     *
     * @param string $email The email address.
     * @param string $ipAddress The IP address.
     * @return int Seconds remaining, or 0 if not locked.
     */
    public function getLockoutSeconds(string $email, string $ipAddress): int
    {
        return $this->loginAttemptManager->getLockoutSecondsRemaining($email, $ipAddress);
    }

    /**
     * Derives a 32-byte encryption key from password and user ID using PBKDF2.
     *
     * Security notes:
     * - Uses user ID as salt (predictable but acceptable here)
     * - PBKDF2 with 100,000 iterations provides protection against brute force
     * - Key is derived from password, so changing password invalidates all encrypted data
     * - This is intentional: notes are user-encrypted and tied to the password
     *
     * @param string $password The user's password.
     * @param int $userId The user's ID.
     * @return string The derived binary key.
     */
    private function deriveEncryptionKey(string $password, int $userId): string
    {
        return hash_pbkdf2('sha256', $password, (string) $userId, 100000, 32, true);
    }

    /**
     * Gets the encryption key from the session.
     *
     * @return string|null The encryption key, or null if not available.
     */
    public function getEncryptionKey(): ?string
    {
        return $_SESSION[self::SESSION_ENCRYPTION_KEY] ?? null;
    }
}
