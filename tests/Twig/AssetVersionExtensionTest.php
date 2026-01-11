<?php

namespace Tests\CrisperCode\Twig;

use CrisperCode\Twig\AssetVersionExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

class AssetVersionExtensionTest extends TestCase
{
    private string $tempDir;
    private string $vendorDir;
    private AssetVersionExtension $extension;

    protected function setUp(): void
    {
        // Create a temporary directory structure for testing
        $this->tempDir = sys_get_temp_dir() . '/asset_version_test_' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/css');
        mkdir($this->tempDir . '/js');

        // Create a vendor directory structure
        $this->vendorDir = sys_get_temp_dir() . '/vendor_test_' . uniqid();
        mkdir($this->vendorDir);
        mkdir($this->vendorDir . '/twbs');
        mkdir($this->vendorDir . '/twbs/bootstrap');
        mkdir($this->vendorDir . '/twbs/bootstrap/dist');
        mkdir($this->vendorDir . '/twbs/bootstrap/dist/css');
        mkdir($this->vendorDir . '/twbs/bootstrap/dist/js');
        mkdir($this->vendorDir . '/components');
        mkdir($this->vendorDir . '/components/jquery');

        $this->extension = new AssetVersionExtension($this->tempDir, $this->vendorDir);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        $this->recursiveDelete($this->tempDir);
        $this->recursiveDelete($this->vendorDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $path = $dir . '/' . $file;
                    if (is_dir($path)) {
                        $this->recursiveDelete($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function testGetFunctionsReturnsAssetVersionFunction(): void
    {
        $functions = $this->extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertEquals('asset_version', $functions[0]->getName());
    }

    public function testAssetVersionAddsCacheBustingHashForCssFiles(): void
    {
        // Create a test CSS file
        $cssContent = 'body { color: red; }';
        file_put_contents($this->tempDir . '/css/test.css', $cssContent);

        $result = $this->extension->assetVersion('/css/test.css');

        // Should have version parameter
        $this->assertMatchesRegularExpression('/^\/css\/test\.css\?v=[a-f0-9]{8}$/', $result);
    }

    public function testAssetVersionAddsCacheBustingHashForJsFiles(): void
    {
        // Create a test JS file
        $jsContent = 'console.log("hello");';
        file_put_contents($this->tempDir . '/js/test.js', $jsContent);

        $result = $this->extension->assetVersion('/js/test.js');

        // Should have version parameter
        $this->assertMatchesRegularExpression('/^\/js\/test\.js\?v=[a-f0-9]{8}$/', $result);
    }

    public function testAssetVersionReturnsOriginalPathForNonExistentFile(): void
    {
        $result = $this->extension->assetVersion('/css/nonexistent.css');

        // Should return path without version
        $this->assertEquals('/css/nonexistent.css', $result);
    }

    public function testAssetVersionReturnsOriginalPathForUnsupportedPaths(): void
    {
        $result = $this->extension->assetVersion('/images/logo.png');

        // Should return path without version
        $this->assertEquals('/images/logo.png', $result);
    }

    public function testHashChangesWhenFileContentChanges(): void
    {
        // Create a test file
        file_put_contents($this->tempDir . '/css/test.css', 'body { color: red; }');
        $result1 = $this->extension->assetVersion('/css/test.css');

        // Change the file content
        file_put_contents($this->tempDir . '/css/test.css', 'body { color: blue; }');
        $result2 = $this->extension->assetVersion('/css/test.css');

        // Extract versions and compare
        preg_match('/\?v=([a-f0-9]{8})$/', $result1, $matches1);
        preg_match('/\?v=([a-f0-9]{8})$/', $result2, $matches2);

        $this->assertNotEquals($matches1[1], $matches2[1], 'Hash should change when file content changes');
    }

    public function testHashStaysSameWhenFileContentIsUnchanged(): void
    {
        // Create a test file
        file_put_contents($this->tempDir . '/css/test.css', 'body { color: red; }');
        $result1 = $this->extension->assetVersion('/css/test.css');
        $result2 = $this->extension->assetVersion('/css/test.css');

        // Should be identical
        $this->assertEquals($result1, $result2, 'Hash should remain same for unchanged file');
    }

    public function testChangingCssDoesNotAffectJsHash(): void
    {
        // Create both CSS and JS files
        file_put_contents($this->tempDir . '/css/styles.css', 'body { color: red; }');
        file_put_contents($this->tempDir . '/js/app.js', 'console.log("hello");');

        // Get initial hashes
        $cssResult1 = $this->extension->assetVersion('/css/styles.css');
        $jsResult1 = $this->extension->assetVersion('/js/app.js');

        // Change only CSS
        file_put_contents($this->tempDir . '/css/styles.css', 'body { color: blue; }');

        // Get new hashes
        $cssResult2 = $this->extension->assetVersion('/css/styles.css');
        $jsResult2 = $this->extension->assetVersion('/js/app.js');

        // CSS hash should change
        $this->assertNotEquals($cssResult1, $cssResult2, 'CSS hash should change');

        // JS hash should remain the same
        $this->assertEquals($jsResult1, $jsResult2, 'JS hash should not change when only CSS changes');
    }

    public function testHandlesNestedPaths(): void
    {
        // Create nested directory structure
        mkdir($this->tempDir . '/js/echarts');
        file_put_contents($this->tempDir . '/js/echarts/echarts.min.js', '// ECharts library');

        $result = $this->extension->assetVersion('/js/echarts/echarts.min.js');

        // Should have version parameter
        $this->assertMatchesRegularExpression('/^\/js\/echarts\/echarts\.min\.js\?v=[a-f0-9]{8}$/', $result);
    }

    public function testHandlesPathWithoutLeadingSlash(): void
    {
        file_put_contents($this->tempDir . '/css/test.css', 'body { color: red; }');

        // Pass path without leading slash
        $result = $this->extension->assetVersion('css/test.css');

        // Should have version parameter
        $this->assertMatchesRegularExpression('/^css\/test\.css\?v=[a-f0-9]{8}$/', $result);
    }

    public function testAssetVersionAddsCacheBustingHashForVendorBootstrapCss(): void
    {
        // Create a test Bootstrap CSS file
        file_put_contents($this->vendorDir . '/twbs/bootstrap/dist/css/bootstrap.min.css', '/* Bootstrap CSS */');

        $result = $this->extension->assetVersion('/twbs/bootstrap/dist/css/bootstrap.min.css');

        // Should have version parameter
        $this->assertMatchesRegularExpression(
            '/^\/twbs\/bootstrap\/dist\/css\/bootstrap\.min\.css\?v=[a-f0-9]{8}$/',
            $result
        );
    }

    public function testAssetVersionAddsCacheBustingHashForVendorBootstrapJs(): void
    {
        // Create a test Bootstrap JS file
        file_put_contents($this->vendorDir . '/twbs/bootstrap/dist/js/bootstrap.bundle.min.js', '/* Bootstrap JS */');

        $result = $this->extension->assetVersion('/twbs/bootstrap/dist/js/bootstrap.bundle.min.js');

        // Should have version parameter
        $this->assertMatchesRegularExpression(
            '/^\/twbs\/bootstrap\/dist\/js\/bootstrap\.bundle\.min\.js\?v=[a-f0-9]{8}$/',
            $result
        );
    }

    public function testAssetVersionAddsCacheBustingHashForVendorJquery(): void
    {
        // Create a test jQuery file
        file_put_contents($this->vendorDir . '/components/jquery/jquery.min.js', '/* jQuery */');

        $result = $this->extension->assetVersion('/components/jquery/jquery.min.js');

        // Should have version parameter
        $this->assertMatchesRegularExpression('/^\/components\/jquery\/jquery\.min\.js\?v=[a-f0-9]{8}$/', $result);
    }

    public function testChangingVendorAssetDoesNotAffectStaticAsset(): void
    {
        // Create both static and vendor files
        file_put_contents($this->tempDir . '/css/styles.css', 'body { color: red; }');
        file_put_contents($this->vendorDir . '/twbs/bootstrap/dist/css/bootstrap.min.css', '/* Bootstrap v1 */');

        // Get initial hashes
        $staticResult1 = $this->extension->assetVersion('/css/styles.css');
        $vendorResult1 = $this->extension->assetVersion('/twbs/bootstrap/dist/css/bootstrap.min.css');

        // Change only vendor file
        file_put_contents($this->vendorDir . '/twbs/bootstrap/dist/css/bootstrap.min.css', '/* Bootstrap v2 */');

        // Get new hashes
        $staticResult2 = $this->extension->assetVersion('/css/styles.css');
        $vendorResult2 = $this->extension->assetVersion('/twbs/bootstrap/dist/css/bootstrap.min.css');

        // Vendor hash should change
        $this->assertNotEquals($vendorResult1, $vendorResult2, 'Vendor hash should change');

        // Static hash should remain the same
        $this->assertEquals($staticResult1, $staticResult2, 'Static hash should not change when only vendor changes');
    }
}
