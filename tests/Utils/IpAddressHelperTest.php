<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Utils;

use CrisperCode\Utils\IpAddressHelper;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tests for IpAddressHelper.
 */
class IpAddressHelperTest extends TestCase
{
    public function testGetClientIpReturnsXForwardedForFromTrustedProxy(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '172.18.0.1', // Docker internal IP (in trusted range)
            'HTTP_X_FORWARDED_FOR' => '203.0.113.42', // Real client IP
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return X-Forwarded-For since request is from trusted proxy
        $this->assertSame('203.0.113.42', $ip);
    }

    public function testGetClientIpReturnsRemoteAddrWhenMissing(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([]);

        $ip = IpAddressHelper::getClientIp($request);

        $this->assertSame('0.0.0.0', $ip);
    }

    public function testGetClientIpIgnoresInvalidIpAddresses(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '172.18.0.1', // Docker internal IP (trusted)
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return REMOTE_ADDR since forwarded IP is invalid
        $this->assertSame('172.18.0.1', $ip);
    }

    public function testGetClientIpIgnoresPrivateRangesInXForwardedFor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '172.18.0.1', // Docker internal IP (trusted)
            'HTTP_X_FORWARDED_FOR' => '192.168.1.1', // Private IP in X-Forwarded-For
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return REMOTE_ADDR since X-Forwarded-For contains private IP
        $this->assertSame('172.18.0.1', $ip);
    }

    public function testGetClientIpHandlesMultipleXForwardedFor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '172.18.0.1', // Docker internal IP (trusted)
            'HTTP_X_FORWARDED_FOR' => '203.0.113.42, 198.51.100.1, 192.0.2.1',
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return the first valid public IP from the chain
        $this->assertSame('203.0.113.42', $ip);
    }

    public function testGetClientIpFromCloudflareProxy(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '104.16.0.1', // Cloudflare IP (trusted)
            'HTTP_X_FORWARDED_FOR' => '203.0.113.42',
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return X-Forwarded-For since request is from Cloudflare
        $this->assertSame('203.0.113.42', $ip);
    }

    public function testGetClientIpFromUntrustedProxy(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '203.0.113.1', // Unknown public IP
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return REMOTE_ADDR since request is not from trusted proxy
        $this->assertSame('203.0.113.1', $ip);
    }

    public function testGetClientIpUsesXRealIpAsFallback(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([
            'REMOTE_ADDR' => '172.18.0.1', // Docker internal IP (trusted)
            'HTTP_X_REAL_IP' => '203.0.113.42',
        ]);

        $ip = IpAddressHelper::getClientIp($request);

        // Should return X-Real-IP since no X-Forwarded-For
        $this->assertSame('203.0.113.42', $ip);
    }
}
