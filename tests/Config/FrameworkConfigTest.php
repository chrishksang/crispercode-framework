<?php

declare(strict_types=1);

namespace CrisperCode\Tests\Config;

use CrisperCode\Config\FrameworkConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FrameworkConfig.
 */
class FrameworkConfigTest extends TestCase
{
    /**
     * Tests that constructor throws exception for empty system master key.
     */
    public function testConstructorThrowsExceptionForEmptySystemMasterKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('System master key cannot be an empty string');

        new FrameworkConfig(
            rootPath: '/tmp',
            systemMasterKey: '',
        );
    }

    /**
     * Tests that constructor accepts null system master key.
     */
    public function testConstructorAcceptsNullSystemMasterKey(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            systemMasterKey: null,
        );

        $this->assertSame('', $config->getSystemMasterKey());
    }

    /**
     * Tests that constructor accepts valid system master key.
     */
    public function testConstructorAcceptsValidSystemMasterKey(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            systemMasterKey: 'valid_key_12345678',
        );

        $this->assertSame('valid_key_12345678', $config->getSystemMasterKey());
    }

    /**
     * Tests that isProduction returns true only for production environment.
     */
    public function testIsProductionReturnsTrueOnlyForProduction(): void
    {
        $prodConfig = new FrameworkConfig(
            rootPath: '/tmp',
            environment: 'production',
        );

        $devConfig = new FrameworkConfig(
            rootPath: '/tmp',
            environment: 'development',
        );

        $testConfig = new FrameworkConfig(
            rootPath: '/tmp',
            environment: 'test',
        );

        $stagingConfig = new FrameworkConfig(
            rootPath: '/tmp',
            environment: 'staging',
        );

        $this->assertTrue($prodConfig->isProduction());
        $this->assertFalse($devConfig->isProduction());
        $this->assertFalse($testConfig->isProduction());
        $this->assertFalse($stagingConfig->isProduction());
    }

    /**
     * Tests that isDevelopment returns true only for development environment.
     */
    public function testIsDevelopmentReturnsTrueOnlyForDevelopment(): void
    {
        $devConfig = new FrameworkConfig(
            rootPath: '/tmp',
            environment: 'development',
        );

        $prodConfig = new FrameworkConfig(
            rootPath: '/tmp',
            environment: 'production',
        );

        $this->assertTrue($devConfig->isDevelopment());
        $this->assertFalse($prodConfig->isDevelopment());
    }

    /**
     * Tests that constructor does not read environment variables.
     */
    public function testConstructorDoesNotReadEnvironmentVariables(): void
    {
        // Set environment variables
        $_ENV['DB_HOST'] = 'env-host';
        $_ENV['DB_NAME'] = 'env-database';
        $_ENV['DB_USER'] = 'env-user';

        // Create config without explicit values
        $config = new FrameworkConfig(
            rootPath: '/tmp',
        );

        // Should use defaults, not environment variables
        $this->assertSame('localhost', $config->getDbHost());
        $this->assertSame('', $config->getDbName());
        $this->assertSame('', $config->getDbUser());

        // Cleanup
        unset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER']);
    }

    /**
     * Tests that fromEnvironment reads from $_ENV.
     */
    public function testFromEnvironmentReadsFromEnv(): void
    {
        // Set environment variables
        $_ENV['ENVIRONMENT'] = 'staging';
        $_ENV['DB_DRIVER'] = 'pgsql';
        $_ENV['DB_HOST'] = 'env-host';
        $_ENV['DB_NAME'] = 'env-database';
        $_ENV['DB_USER'] = 'env-user';
        $_ENV['DB_PASSWORD'] = 'env-password';
        $_ENV['DB_PORT'] = '5432';
        $_ENV['SYSTEM_MASTER_KEY'] = 'env-key-12345678';

        $config = FrameworkConfig::fromEnvironment('/tmp', 'TestApp');

        $this->assertSame('staging', $config->getEnvironment());
        $this->assertSame('pgsql', $config->getDbDriver());
        $this->assertSame('env-host', $config->getDbHost());
        $this->assertSame('env-database', $config->getDbName());
        $this->assertSame('env-user', $config->getDbUser());
        $this->assertSame('env-password', $config->getDbPassword());
        $this->assertSame(5432, $config->getDbPort());
        $this->assertSame('env-key-12345678', $config->getSystemMasterKey());

        // Cleanup
        unset($_ENV['ENVIRONMENT'], $_ENV['DB_DRIVER'], $_ENV['DB_HOST'], $_ENV['DB_NAME']);
        unset($_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_PORT'], $_ENV['SYSTEM_MASTER_KEY']);
    }

    /**
     * Tests that fromEnvironment falls back to $_SERVER.
     */
    public function testFromEnvironmentFallsBackToServer(): void
    {
        // Set only in $_SERVER
        $_SERVER['DB_HOST'] = 'server-host';

        $config = FrameworkConfig::fromEnvironment('/tmp');

        $this->assertSame('server-host', $config->getDbHost());

        // Cleanup
        unset($_SERVER['DB_HOST']);
    }

    /**
     * Tests that fromEnvironment prefers $_ENV over $_SERVER.
     */
    public function testFromEnvironmentPrefersEnvOverServer(): void
    {
        $_ENV['DB_HOST'] = 'env-host';
        $_SERVER['DB_HOST'] = 'server-host';

        $config = FrameworkConfig::fromEnvironment('/tmp');

        $this->assertSame('env-host', $config->getDbHost());

        // Cleanup
        unset($_ENV['DB_HOST'], $_SERVER['DB_HOST']);
    }

    /**
     * Tests that fromEnvironment supports legacy MYSQL_* variables.
     */
    public function testFromEnvironmentSupportsLegacyMysqlVariables(): void
    {
        $_ENV['MYSQL_HOST'] = 'legacy-host';
        $_ENV['MYSQL_DATABASE'] = 'legacy-db';
        $_ENV['MYSQL_USER'] = 'legacy-user';
        $_ENV['MYSQL_PASSWORD'] = 'legacy-pass';
        $_ENV['MYSQL_PORT'] = '3307';

        $config = FrameworkConfig::fromEnvironment('/tmp');

        $this->assertSame('legacy-host', $config->getDbHost());
        $this->assertSame('legacy-db', $config->getDbName());
        $this->assertSame('legacy-user', $config->getDbUser());
        $this->assertSame('legacy-pass', $config->getDbPassword());
        $this->assertSame(3307, $config->getDbPort());

        // Cleanup
        unset($_ENV['MYSQL_HOST'], $_ENV['MYSQL_DATABASE'], $_ENV['MYSQL_USER']);
        unset($_ENV['MYSQL_PASSWORD'], $_ENV['MYSQL_PORT']);
    }

    /**
     * Tests that fromEnvironment prefers new DB_* variables over legacy MYSQL_*.
     */
    public function testFromEnvironmentPrefersNewVariablesOverLegacy(): void
    {
        $_ENV['DB_HOST'] = 'new-host';
        $_ENV['MYSQL_HOST'] = 'legacy-host';

        $config = FrameworkConfig::fromEnvironment('/tmp');

        $this->assertSame('new-host', $config->getDbHost());

        // Cleanup
        unset($_ENV['DB_HOST'], $_ENV['MYSQL_HOST']);
    }

    /**
     * Tests that fromEnvironment uses default values when env vars not set.
     */
    public function testFromEnvironmentUsesDefaultsWhenNotSet(): void
    {
        // Save and clear environment variables that might be set by bootstrap
        $savedEnv = $_ENV['ENVIRONMENT'] ?? null;
        $savedSystemKey = $_ENV['SYSTEM_MASTER_KEY'] ?? null;
        unset($_ENV['ENVIRONMENT'], $_ENV['SYSTEM_MASTER_KEY']);

        $config = FrameworkConfig::fromEnvironment('/tmp');

        $this->assertSame('production', $config->getEnvironment());
        $this->assertSame('mysql', $config->getDbDriver());
        $this->assertSame('localhost', $config->getDbHost());
        $this->assertSame(3306, $config->getDbPort());

        // Restore environment variables
        if ($savedEnv !== null) {
            $_ENV['ENVIRONMENT'] = $savedEnv;
        }
        if ($savedSystemKey !== null) {
            $_ENV['SYSTEM_MASTER_KEY'] = $savedSystemKey;
        }
    }
}
