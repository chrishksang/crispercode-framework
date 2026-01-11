<?php

namespace CrisperCode\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that provides cache-busting for static assets.
 *
 * This extension adds a hash-based query parameter to asset URLs,
 * ensuring that browsers always fetch the latest version when a file changes.
 *
 * @package CrisperCode\Twig
 */
class AssetVersionExtension extends AbstractExtension
{
    /**
     * The base path for static assets on the filesystem.
     */
    private string $staticPath;

    /**
     * The base path for vendor assets on the filesystem.
     */
    private string $vendorPath;

    /**
     * Constructor.
     *
     * @param string $staticPath The base path for static assets.
     * @param string $vendorPath The base path for vendor assets.
     */
    public function __construct(string $staticPath, string $vendorPath)
    {
        $this->staticPath = $staticPath;
        $this->vendorPath = $vendorPath;
    }

    /**
     * Registers the `asset_version` function.
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_version', [$this, 'assetVersion']),
        ];
    }

    /**
     * Returns the asset path with a cache-busting version parameter.
     *
     * The version is based on the file content hash, so it only changes
     * when the actual file content changes.
     *
     * @param string $path The asset path (e.g., '/css/dashboard.css').
     *
     * @return string The asset path with version parameter (e.g., '/css/dashboard.css?v=a1b2c3d4').
     *
     * @example
     * <link rel="stylesheet" href="{{ asset_version('/css/styles.css') }}">
     */
    public function assetVersion(string $path): string
    {
        $filePath = $this->resolveFilePath($path);

        if ($filePath === null || !file_exists($filePath)) {
            // If file doesn't exist, return path as-is
            return $path;
        }

        // Use content hash for cache busting (first 8 chars of md5)
        $hash = substr(md5_file($filePath), 0, 8);

        // Append hash as query parameter
        return $path . '?v=' . $hash;
    }

    /**
     * Resolves the asset URL path to a local file system path.
     *
     * @param string $path The asset URL path.
     *
     * @return string|null The resolved file path, or null if not resolvable.
     */
    private function resolveFilePath(string $path): ?string
    {
        // Remove leading slash to make it relative
        $relativePath = ltrim($path, '/');

        // Check if it's a static asset (css, js, etc.)
        if (str_starts_with($relativePath, 'css/') || str_starts_with($relativePath, 'js/')) {
            return $this->staticPath . '/' . $relativePath;
        }

        // Check if it's a vendor asset (twbs/bootstrap, components/jquery, etc.)
        if (str_starts_with($relativePath, 'twbs/') || str_starts_with($relativePath, 'components/')) {
            return $this->vendorPath . '/' . $relativePath;
        }

        return null;
    }
}
