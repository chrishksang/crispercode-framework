<?php

declare(strict_types=1);

/**
 * Bootstrap file for CrisperCode Framework PHPUnit tests.
 *
 * This file is automatically loaded before any tests run (configured in phpunit.xml).
 * It provides:
 * - Test environment configuration
 * - Database connection helpers (SQLite in-memory preferred)
 * - Test data factories for framework entities
 * - Schema setup helpers
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Suppress warnings in test environment
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// Set test environment variables
$_ENV['ENVIRONMENT'] = 'test';
$_ENV['SYSTEM_MASTER_KEY'] = 'test_system_key_for_testing_only_12345678';

// Also define as constant as a fallback
if (!defined('SYSTEM_MASTER_KEY')) {
    define('SYSTEM_MASTER_KEY', 'test_system_key_for_testing_only_12345678');
}

// Define REQUEST_TIME if not set
if (!defined('REQUEST_TIME')) {
    define('REQUEST_TIME', time());
}

// Define ROOT_PATH for framework tests
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '/..'));
}

/**
 * Check if SQLite PDO extension is available.
 *
 * @return bool True if pdo_sqlite extension is loaded.
 */
function isSQLiteAvailable(): bool
{
    return extension_loaded('pdo_sqlite');
}

/**
 * Create a test database connection.
 *
 * Prefers SQLite in-memory for speed and isolation. Falls back to MySQL
 * if SQLite is unavailable and TEST_MYSQL_DSN is configured.
 *
 * @return MeekroDB The configured test database connection.
 * @throws RuntimeException If no suitable database driver is available.
 */
function createTestDatabase(): MeekroDB
{
    // Prefer SQLite for speed and isolation
    if (isSQLiteAvailable()) {
        $dsn = 'sqlite::memory:';
        $db = new MeekroDB($dsn, '', '');
        // Enable foreign keys for SQLite
        $db->query('PRAGMA foreign_keys = ON');
        return $db;
    }

    // Fallback to MySQL if available (for environments without SQLite)
    $testMysqlDsn = $_ENV['TEST_MYSQL_DSN'] ?? $_SERVER['TEST_MYSQL_DSN'] ?? null;
    if ($testMysqlDsn !== null) {
        return new MeekroDB(
            $testMysqlDsn,
            $_ENV['TEST_MYSQL_USER'] ?? $_SERVER['TEST_MYSQL_USER'] ?? 'test',
            $_ENV['TEST_MYSQL_PASSWORD'] ?? $_SERVER['TEST_MYSQL_PASSWORD'] ?? 'test'
        );
    }

    throw new RuntimeException(
        "No test database available. Please either:\n" .
        "1. Install pdo_sqlite extension (recommended), OR\n" .
        "2. Set TEST_MYSQL_DSN environment variable to use MySQL\n\n" .
        "To install SQLite:\n" .
        "  - Docker: Add 'pdo_sqlite' to install-php-extensions in Dockerfile\n" .
        "  - Ubuntu/Debian: apt-get install php-sqlite3\n" .
        "  - macOS: brew install php (includes SQLite)\n" .
        "  - Windows: Enable extension=pdo_sqlite in php.ini"
    );
}

/**
 * Seed the test database with schema from framework entity classes.
 *
 * Uses SchemaManager to automatically create tables based on entity attributes.
 *
 * @param MeekroDB $db The database connection to seed.
 */
function seedTestDatabase(MeekroDB $db): void
{
    $schemaManager = new \CrisperCode\Database\SchemaManager($db);
    $schemaManager->setQuiet(true); // Suppress output during test setup

    $entities = [
        \CrisperCode\Entity\User::class,
        \CrisperCode\Entity\LoginAttempt::class,
        \CrisperCode\Entity\RememberToken::class,
        \CrisperCode\Entity\EmailVerificationToken::class,
        \CrisperCode\Entity\KeyValue::class,
    ];

    foreach ($entities as $entityClass) {
        $schemaManager->syncTable($entityClass);
    }
}

/**
 * Factory class for creating test data.
 *
 * Provides methods to create test entities with sensible defaults
 * and the ability to override specific fields.
 */
class TestDataFactory
{
    /**
     * Create a test user.
     *
     * @param MeekroDB $db The database connection.
     * @param array<string, mixed> $overrides Optional field overrides.
     * @return \CrisperCode\Entity\User The created user entity.
     */
    public static function createUser(MeekroDB $db, array $overrides = []): \CrisperCode\Entity\User
    {
        $defaults = [
            'email' => 'test' . uniqid() . '@example.com',
            'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $overrides);

        $user = new \CrisperCode\Entity\User($db);
        $user->loadFromValues($data);
        $user->save();

        return $user;
    }

    /**
     * Create a test login attempt.
     *
     * @param MeekroDB $db The database connection.
     * @param array<string, mixed> $overrides Optional field overrides.
     * @return \CrisperCode\Entity\LoginAttempt The created login attempt entity.
     */
    public static function createLoginAttempt(MeekroDB $db, array $overrides = []): \CrisperCode\Entity\LoginAttempt
    {
        $defaults = [
            'email' => 'test@example.com',
            'ip_address' => '127.0.0.1',
            'successful' => 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $overrides);

        $attempt = new \CrisperCode\Entity\LoginAttempt($db);
        $attempt->loadFromValues($data);
        $attempt->save();

        return $attempt;
    }

    /**
     * Create a test remember token.
     *
     * @param MeekroDB $db The database connection.
     * @param int $userId The ID of the user who owns the token.
     * @param array<string, mixed> $overrides Optional field overrides.
     * @return \CrisperCode\Entity\RememberToken The created remember token entity.
     */
    public static function createRememberToken(MeekroDB $db, int $userId, array $overrides = []): \CrisperCode\Entity\RememberToken
    {
        $defaults = [
            'user_id' => $userId,
            'series' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'encrypted_encryption_key' => 'encrypted_key',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'user_agent' => 'Test Browser',
            'ip_address' => '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $overrides);

        $token = new \CrisperCode\Entity\RememberToken($db);
        $token->loadFromValues($data);
        $token->save();

        return $token;
    }

    /**
     * Create a test email verification token.
     *
     * @param MeekroDB $db The database connection.
     * @param int $userId The ID of the user who owns the token.
     * @param array<string, mixed> $overrides Optional field overrides.
     * @return \CrisperCode\Entity\EmailVerificationToken The created token entity.
     */
    public static function createEmailVerificationToken(
        MeekroDB $db,
        int $userId,
        array $overrides = []
    ): \CrisperCode\Entity\EmailVerificationToken {
        $defaults = [
            'user_id' => $userId,
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $overrides);

        $token = new \CrisperCode\Entity\EmailVerificationToken($db);
        $token->loadFromValues($data);
        $token->save();

        return $token;
    }
}
