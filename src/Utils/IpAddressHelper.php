<?php

declare(strict_types=1);

namespace CrisperCode\Utils;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Helper class for safely extracting client IP addresses from HTTP requests.
 *
 * This class addresses IP spoofing vulnerabilities by:
 * - Only trusting proxy headers when explicitly configured
 * - Validating IP addresses before returning them
 * - Providing a secure fallback to REMOTE_ADDR
 *
 * @package CrisperCode\Utils
 */
class IpAddressHelper
{
    /**
     * List of trusted proxy IP addresses or CIDR ranges.
     * When empty, proxy headers (X-Forwarded-For, X-Real-IP) are NOT trusted.
     *
     * Includes:
     * - Private network ranges (for Docker/internal proxies)
     * - Cloudflare IPv4 ranges (for external CDN)
     *
     * @var array<string>
     */
    private const TRUSTED_PROXIES = [
        // Private network ranges (Docker, internal proxies)
        // These are trusted because the app runs behind a local reverse proxy
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',

        // Cloudflare IPv4 ranges (https://www.cloudflare.com/ips-v4)
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Gets the client IP address from a PSR-7 request.
     *
     * Security considerations:
     * - Only trusts X-Forwarded-For/X-Real-IP headers when request comes from a trusted proxy
     * - Always validates IP addresses
     * - Falls back to REMOTE_ADDR which cannot be spoofed
     *
     * @param ServerRequestInterface $request The HTTP request.
     * @return string The client IP address.
     */
    public static function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';

        // If no trusted proxies configured, always use REMOTE_ADDR
        if (count(self::TRUSTED_PROXIES) === 0) {
            return $remoteAddr;
        }

        // Check if the request comes from a trusted proxy
        if (!self::isIpTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        // Check X-Forwarded-For header (most common)
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $serverParams['HTTP_X_FORWARDED_FOR']));
            // Return the first IP in the chain (original client)
            foreach ($ips as $ip) {
                if (self::isValidIp($ip)) {
                    return $ip;
                }
            }
        }

        // Check X-Real-IP header (used by nginx and others)
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            $ip = trim($serverParams['HTTP_X_REAL_IP']);
            if (self::isValidIp($ip)) {
                return $ip;
            }
        }

        // Fallback to REMOTE_ADDR
        return $remoteAddr;
    }

    /**
     * Validates an IP address (IPv4 or IPv6).
     *
     * @param string $ip The IP address to validate.
     * @return bool True if valid.
     */
    private static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Checks if an IP address is in the trusted proxies list.
     *
     * Supports both individual IPs and CIDR notation.
     * Note: TRUSTED_PROXIES is empty by default but can be configured.
     *
     * @param string $ip The IP address to check.
     * @return bool True if trusted.
     */
    private static function isIpTrusted(string $ip): bool
    {
        // Early return if no trusted proxies configured
        if (count(self::TRUSTED_PROXIES) === 0) {
            return false;
        }

        foreach (self::TRUSTED_PROXIES as $trustedProxy) {
            if (str_contains($trustedProxy, '/')) {
                // CIDR notation
                if (self::ipInRange($ip, $trustedProxy)) {
                    return true;
                }
            } elseif ($ip === $trustedProxy) {
                // Direct IP match
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if an IP is within a CIDR range.
     *
     * @param string $ip The IP address to check.
     * @param string $cidr The CIDR range (e.g., '192.168.0.0/24').
     * @return bool True if IP is in range.
     */
    private static function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
