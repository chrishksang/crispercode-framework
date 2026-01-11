<?php

declare(strict_types=1);

namespace CrisperCode\Config;

/**
 * Central configuration for the CrisperCode framework.
 *
 * This class provides all configurable paths and settings that the framework needs.
 * Applications should create an instance with their specific configuration and
 * register it in the DI container.
 *
 * The constructor accepts explicit configuration values only. To create a config
 * from environment variables, use the static fromEnvironment() method.
 *
 * Example usage in bootstrap.php:
 *
 *     FrameworkConfig::class => factory(function (): FrameworkConfig {
 *         return new FrameworkConfig(
 *             rootPath: ROOT_PATH,
 *             environment: 'production',
 *             appName: 'MyApp',
 *         );
 *     }),
 *
 * Or from environment variables:
 *
 *     FrameworkConfig::class => factory(function (): FrameworkConfig {
 *         return FrameworkConfig::fromEnvironment(
 *             rootPath: ROOT_PATH,
 *             appName: 'MyApp',
 *         );
 *     }),
 */
class FrameworkConfig
{
    private string $rootPath;
    private string $environment;
    private string $appName;
    private string $sessionName;
    private string $templatesPath;
    private string $cachePath;
    private string $staticPath;
    private string $vendorPath;
    private string $storagePath;
    private string $uploadPath;

    // Database configuration
    private string $dbDriver;
    private string $dbHost;
    private string $dbName;
    private string $dbUser;
    private string $dbPassword;
    private int $dbPort;
    private ?string $dbDsn;

    // Security
    private string $systemMasterKey;

    public function __construct(
        string $rootPath,
        string $environment = 'production',
        string $appName = 'Application',
        ?string $sessionName = null,
        ?string $templatesPath = null,
        ?string $cachePath = null,
        ?string $staticPath = null,
        ?string $vendorPath = null,
        ?string $storagePath = null,
        ?string $uploadPath = null,
        ?string $dbDriver = null,
        ?string $dbHost = null,
        ?string $dbName = null,
        ?string $dbUser = null,
        ?string $dbPassword = null,
        ?int $dbPort = null,
        ?string $dbDsn = null,
        ?string $systemMasterKey = null,
    ) {
        $this->rootPath = rtrim($rootPath, '/');
        $this->environment = $environment;
        $this->appName = $appName;

        // Session name defaults to lowercase app name with _session suffix
        $this->sessionName = $sessionName ?? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $appName)) . '_session';

        // Paths default to standard locations relative to rootPath
        $this->templatesPath = $templatesPath ?? $this->rootPath . '/templates';
        $this->cachePath = $cachePath ?? $this->rootPath . '/cache';
        $this->staticPath = $staticPath ?? $this->rootPath . '/static';
        $this->vendorPath = $vendorPath ?? $this->rootPath . '/vendor';
        $this->storagePath = $storagePath ?? $this->rootPath . '/storage';
        $this->uploadPath = $uploadPath ?? $this->storagePath . '/uploads';

        // Database configuration - explicit values only, no environment reading
        $this->dbDriver = $dbDriver ?? 'mysql';
        $this->dbHost = $dbHost ?? 'localhost';
        $this->dbName = $dbName ?? '';
        $this->dbUser = $dbUser ?? '';
        $this->dbPassword = $dbPassword ?? '';
        $this->dbPort = $dbPort ?? 3306;
        $this->dbDsn = $dbDsn;

        // Security - require explicit system master key
        if ($systemMasterKey !== null && $systemMasterKey === '') {
            throw new \InvalidArgumentException(
                'System master key cannot be an empty string. ' .
                'Either provide a non-empty key or pass null to indicate no encryption is configured.'
            );
        }
        $this->systemMasterKey = $systemMasterKey ?? '';
    }

    /**
     * Create a configuration instance from environment variables.
     * Useful for quick setup where all config comes from env.
     *
     * Reads from $_ENV (preferred) with fallback to $_SERVER.
     */
    public static function fromEnvironment(string $rootPath, string $appName = 'Application'): self
    {
        return new self(
            rootPath: $rootPath,
            environment: self::getEnv('ENVIRONMENT') ?? 'production',
            appName: $appName,
            dbDriver: self::getEnv('DB_DRIVER'),
            dbHost: self::getEnv('DB_HOST') ?? self::getEnv('MYSQL_HOST'),
            dbName: self::getEnv('DB_NAME') ?? self::getEnv('MYSQL_DATABASE'),
            dbUser: self::getEnv('DB_USER') ?? self::getEnv('MYSQL_USER'),
            dbPassword: self::getEnv('DB_PASSWORD') ?? self::getEnv('MYSQL_PASSWORD'),
            dbPort: self::getEnv('DB_PORT') !== null
                ? (int) self::getEnv('DB_PORT')
                : (self::getEnv('MYSQL_PORT') !== null ? (int) self::getEnv('MYSQL_PORT') : null),
            dbDsn: self::getEnv('DB_DSN'),
            systemMasterKey: self::getEnv('SYSTEM_MASTER_KEY'),
        );
    }

    /**
     * Get environment variable from $_ENV or $_SERVER.
     *
     * @param string $key Environment variable name.
     * @return string|null The value or null if not set.
     */
    private static function getEnv(string $key): ?string
    {
        // Prefer $_ENV, fallback to $_SERVER
        return $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isDevelopment(): bool
    {
        return $this->environment === 'development';
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function getAppName(): string
    {
        return $this->appName;
    }

    public function getSessionName(): string
    {
        return $this->sessionName;
    }

    public function getTemplatesPath(): string
    {
        return $this->templatesPath;
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    public function getStaticPath(): string
    {
        return $this->staticPath;
    }

    public function getVendorPath(): string
    {
        return $this->vendorPath;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getUploadPath(): string
    {
        return $this->uploadPath;
    }

    public function getDbDriver(): string
    {
        return $this->dbDriver;
    }

    public function getDbHost(): string
    {
        return $this->dbHost;
    }

    public function getDbName(): string
    {
        return $this->dbName;
    }

    public function getDbUser(): string
    {
        return $this->dbUser;
    }

    public function getDbPassword(): string
    {
        return $this->dbPassword;
    }

    public function getDbPort(): int
    {
        return $this->dbPort;
    }

    public function getDbDsn(): ?string
    {
        return $this->dbDsn;
    }

    public function getSystemMasterKey(): string
    {
        return $this->systemMasterKey;
    }
}
