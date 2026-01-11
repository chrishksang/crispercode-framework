<?php

declare(strict_types=1);

namespace CrisperCode\File;

use CrisperCode\Config\FrameworkConfig;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Manager for file operations.
 *
 * Handles file uploads, deletions, and creation from content.
 *
 * @package CrisperCode\File
 */
class FileManager
{
    private string $uploadDir;
    private string $storageDir;
    private LoggerInterface $logger;

    /**
     * FileManager constructor.
     *
     * @param LoggerInterface $logger Logger instance.
     * @param FrameworkConfig $config Framework configuration for paths.
     */
    public function __construct(LoggerInterface $logger, FrameworkConfig $config)
    {
        $this->logger = $logger;
        $this->uploadDir = $config->getUploadPath();
        $this->storageDir = $config->getStoragePath();
    }

    /**
     * Handles file upload and returns URI and type.
     *
     * @param UploadedFileInterface $uploadedFile The uploaded file.
     *
     * @return array{uri: string, type: string, error: bool} Upload result.
     */
    public function handleFileUpload(UploadedFileInterface $uploadedFile): array
    {
        // Ensure upload directory exists
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $clientFilename = $uploadedFile->getClientFilename();
        $extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $basename = pathinfo($clientFilename, PATHINFO_FILENAME);
        $mimeType = $uploadedFile->getClientMediaType();

        // Generate unique filename
        $filename = $basename . '_' . uniqid() . '.' . $extension;
        $targetPath = $this->uploadDir . '/' . $filename;

        try {
            $uploadedFile->moveTo($targetPath);

            $this->logger->info('File uploaded successfully', [
                'filename' => $filename,
                'path' => $targetPath,
                'original_name' => $clientFilename,
                'mime_type' => $mimeType,
                'size' => $uploadedFile->getSize(),
            ]);

            return [
                'uri' => 'file://' . $targetPath,
                'type' => $mimeType ?? 'application/octet-stream',
                'error' => false,
            ];
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'filename' => $clientFilename,
                'target_path' => $targetPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'uri' => '',
                'type' => '',
                'error' => true,
            ];
        }
    }

    /**
     * Deletes a physical file from the filesystem.
     *
     * @param string $uri The file URI.
     */
    public function deletePhysicalFile(string $uri): void
    {
        // Extract path from file:// URI
        if (str_starts_with($uri, 'file://')) {
            $path = substr($uri, 7);
            if (file_exists($path) && is_file($path)) {
                $fileSize = filesize($path);
                $deleted = unlink($path);

                if ($deleted) {
                    $this->logger->info('File deleted from filesystem', [
                        'path' => $path,
                        'uri' => $uri,
                        'size' => $fileSize,
                    ]);
                } else {
                    $this->logger->warning('Failed to delete file from filesystem', [
                        'path' => $path,
                        'uri' => $uri,
                    ]);
                }
            } else {
                $this->logger->warning('File not found for deletion', [
                    'path' => $path,
                    'uri' => $uri,
                ]);
            }
        }
    }

    /**
     * Saves file content to disk and returns URI and type.
     *
     * This method is useful when you have programmatically generated or fetched
     * content (e.g., from an API) that needs to be saved as a file.
     *
     * @param string $content The file content to save.
     * @param string $filename The desired filename (should include extension).
     * @param string|null $mimeType Optional MIME type. If not provided, will be detected from filename extension.
     *
     * @return array{uri: string, type: string, error: bool} Save result.
     */
    public function saveFileFromContent(string $content, string $filename, ?string $mimeType = null): array
    {
        // Ensure storage directory exists
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // Generate unique filename to avoid conflicts
        $uniqueFilename = $basename . '_' . uniqid() . '.' . $extension;
        $targetPath = $this->storageDir . '/' . $uniqueFilename;

        // Detect MIME type if not provided
        if ($mimeType === null) {
            $mimeType = $this->getMimeTypeFromExtension($extension);
        }

        try {
            $bytesWritten = file_put_contents($targetPath, $content);

            if ($bytesWritten === false) {
                throw new \RuntimeException('Failed to write file content');
            }

            $this->logger->info('File saved from content successfully', [
                'filename' => $uniqueFilename,
                'path' => $targetPath,
                'original_name' => $filename,
                'mime_type' => $mimeType,
                'size' => $bytesWritten,
            ]);

            return [
                'uri' => 'file://' . $targetPath,
                'type' => $mimeType,
                'error' => false,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to save file from content', [
                'filename' => $filename,
                'target_path' => $targetPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'uri' => '',
                'type' => '',
                'error' => true,
            ];
        }
    }

    /**
     * Gets MIME type from file extension.
     *
     * @param string $extension The file extension.
     *
     * @return string The MIME type.
     */
    private function getMimeTypeFromExtension(string $extension): string
    {
        $mimeTypes = [
            'txt' => 'text/plain',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'zip' => 'application/zip',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
        ];

        $lowercaseExtension = strtolower($extension);
        return $mimeTypes[$lowercaseExtension] ?? 'application/octet-stream';
    }
}
