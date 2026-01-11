<?php

declare(strict_types=1);

namespace CrisperCode\Utils;

use CrisperCode\Config\FrameworkConfig;

/**
 * Session configuration with security-hardened settings.
 *
 * This class provides secure session configuration to protect against:
 * - Session fixation attacks
 * - Session hijacking
 * - XSS-based session theft
 * - CSRF attacks
 */
class SessionConfig
{
    /**
     * Initializes a secure PHP session with hardened configuration.
     *
     * This should be called before session_start() in the application bootstrap.
     * Security features:
     * - HttpOnly cookies (prevents JavaScript access)
     * - Secure cookies (HTTPS only) when not in development
     * - SameSite=Lax (CSRF protection)
     * - Strict session ID regeneration
     * - Use only cookies for session ID (no URL parameters)
     */
    public static function configure(FrameworkConfig $config): void
    {
        // Prevent session ID in URLs
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        // Use strict session ID generation
        ini_set('session.use_strict_mode', '1');

        // Configure secure cookie parameters
        session_set_cookie_params([
            'lifetime' => 0, // Session cookie (expires when browser closes)
            'path' => '/',
            'domain' => '', // Current domain
            'secure' => $config->isProduction(), // HTTPS only in production
            'httponly' => true, // Prevent JavaScript access
            'samesite' => 'Lax', // CSRF protection
        ]);

        // Set session name from config
        session_name($config->getSessionName());

        // Configure session garbage collection
        ini_set('session.gc_maxlifetime', '86400'); // 24 hours
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
}
