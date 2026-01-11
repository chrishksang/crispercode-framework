<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Utils;

use CrisperCode\Config\FrameworkConfig;
use CrisperCode\Utils\SessionConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionConfig.
 */
class SessionConfigTest extends TestCase
{
    private FrameworkConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up any existing session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->config = new FrameworkConfig(
            rootPath: '/tmp',
            appName: 'TestApp'
        );
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        parent::tearDown();
    }

    public function testConfigureSetsSecureSessionParameters(): void
    {
        SessionConfig::configure($this->config);

        // Verify session configuration
        $this->assertSame('1', ini_get('session.use_only_cookies'));
        $this->assertSame('0', ini_get('session.use_trans_sid'));
        $this->assertSame('1', ini_get('session.use_strict_mode'));

        // Verify session name (derived from appName 'TestApp' -> 'testapp_session')
        $this->assertSame('testapp_session', session_name());
    }

    public function testConfigureCanBeCalledMultipleTimes(): void
    {
        SessionConfig::configure($this->config);
        SessionConfig::configure($this->config);

        // Should not throw an exception
        $this->assertSame('testapp_session', session_name());
    }
}
