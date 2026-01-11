<?php

namespace Tests\CrisperCode\File;

if (!defined('REQUEST_TIME')) {
    define('REQUEST_TIME', strtotime('2025-01-01 00:00:00'));
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/../..');
}

use CrisperCode\Config\FrameworkConfig;
use CrisperCode\File\FileManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

class FileManagerTest extends TestCase
{
    protected $loggerMock;

    protected $fileManager;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $config = new FrameworkConfig(
            rootPath: ROOT_PATH
        );
        $this->fileManager = new FileManager($this->loggerMock, $config);
    }

    public function testHandleFileUploadSuccess(): void
    {
        $uploadedFileMock = $this->createMock(UploadedFileInterface::class);

        $uploadedFileMock->method('getClientFilename')
            ->willReturn('test-document.pdf');

        $uploadedFileMock->method('getClientMediaType')
            ->willReturn('application/pdf');

        $uploadedFileMock->method('getSize')
            ->willReturn(1024);

        $uploadedFileMock->expects($this->once())
            ->method('moveTo')
            ->with(
                $this->callback(function ($path) {
                    // Verify path contains ROOT_PATH and has the expected structure
                    return str_contains($path, ROOT_PATH . '/storage/uploads/')
                        && str_contains($path, 'test-document_')
                        && str_ends_with($path, '.pdf');
                })
            );

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('File uploaded successfully', $this->isType('array'));

        $result = $this->fileManager->handleFileUpload($uploadedFileMock);

        $this->assertFalse($result['error']);
        $this->assertStringStartsWith('file://', $result['uri']);
        $this->assertEquals('application/pdf', $result['type']);
    }

    public function testHandleFileUploadFailure(): void
    {
        $uploadedFileMock = $this->createMock(UploadedFileInterface::class);

        $uploadedFileMock->method('getClientFilename')
            ->willReturn('test-document.pdf');

        $uploadedFileMock->method('getClientMediaType')
            ->willReturn('application/pdf');

        $uploadedFileMock->method('moveTo')
            ->willThrowException(new \RuntimeException('Failed to move uploaded file'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('File upload failed', $this->isType('array'));

        $result = $this->fileManager->handleFileUpload($uploadedFileMock);

        $this->assertTrue($result['error']);
        $this->assertEquals('', $result['uri']);
        $this->assertEquals('', $result['type']);
    }

    public function testDeletePhysicalFileSuccess(): void
    {
        // Create a temporary test file
        $tempFilePath = '/tmp/test_delete_' . uniqid() . '.txt';
        file_put_contents($tempFilePath, 'Test content');

        try {
            $uri = 'file://' . $tempFilePath;

            $this->loggerMock->expects($this->once())
                ->method('info')
                ->with('File deleted from filesystem', $this->isType('array'));

            $this->fileManager->deletePhysicalFile($uri);

            // Verify file was deleted
            $this->assertFileDoesNotExist($tempFilePath);
        } finally {
            // Clean up if file still exists
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }

    public function testDeletePhysicalFileNotFound(): void
    {
        $uri = 'file:///non/existent/file.txt';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('File not found for deletion', $this->isType('array'));

        $this->fileManager->deletePhysicalFile($uri);
    }

    public function testDeletePhysicalFileInvalidUri(): void
    {
        $uri = 'http://example.com/file.txt';

        $this->loggerMock->expects($this->never())
            ->method('info');

        $this->loggerMock->expects($this->never())
            ->method('warning');

        $this->fileManager->deletePhysicalFile($uri);
    }

    public function testSaveFileFromContentSuccess(): void
    {
        $content = 'Test file content';
        $filename = 'test-file.txt';

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('File saved from content successfully', $this->isType('array'));

        $result = $this->fileManager->saveFileFromContent($content, $filename);

        $this->assertFalse($result['error']);
        $this->assertStringStartsWith('file://', $result['uri']);
        $this->assertEquals('text/plain', $result['type']);

        // Clean up the created file
        if (!$result['error'] && str_starts_with($result['uri'], 'file://')) {
            $path = substr($result['uri'], 7);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testSaveFileFromContentWithCustomMimeType(): void
    {
        $content = 'Custom content';
        $filename = 'custom-file.xyz';
        $mimeType = 'application/custom';

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('File saved from content successfully', $this->isType('array'));

        $result = $this->fileManager->saveFileFromContent($content, $filename, $mimeType);

        $this->assertFalse($result['error']);
        $this->assertEquals('application/custom', $result['type']);

        // Clean up the created file
        if (!$result['error'] && str_starts_with($result['uri'], 'file://')) {
            $path = substr($result['uri'], 7);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testSaveFileFromContentWithVariousExtensions(): void
    {
        $testCases = [
            ['extension' => 'pdf', 'expectedMimeType' => 'application/pdf'],
            ['extension' => 'json', 'expectedMimeType' => 'application/json'],
            ['extension' => 'jpg', 'expectedMimeType' => 'image/jpeg'],
            ['extension' => 'png', 'expectedMimeType' => 'image/png'],
            ['extension' => 'csv', 'expectedMimeType' => 'text/csv'],
            ['extension' => 'xyz', 'expectedMimeType' => 'application/octet-stream'], // Unknown extension
        ];

        foreach ($testCases as $testCase) {
            $content = 'Test content';
            $filename = 'test-file.' . $testCase['extension'];

            $result = $this->fileManager->saveFileFromContent($content, $filename);

            $this->assertFalse($result['error'], "Failed for extension: {$testCase['extension']}");
            $this->assertEquals(
                $testCase['expectedMimeType'],
                $result['type'],
                "Wrong MIME type for extension: {$testCase['extension']}"
            );

            // Clean up the created file
            if (!$result['error'] && str_starts_with($result['uri'], 'file://')) {
                $path = substr($result['uri'], 7);
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }
}
