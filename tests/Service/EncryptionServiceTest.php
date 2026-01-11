<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service;

use CrisperCode\Service\EncryptionService;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        $this->service = new EncryptionService();
    }

    public function testEncryptAndDecrypt(): void
    {
        $key = $this->service->generateKey();
        $plaintext = "This is a secret message.";

        $encrypted = $this->service->encrypt($plaintext, $key);

        $this->assertArrayHasKey('content', $encrypted);
        $this->assertArrayHasKey('iv', $encrypted);
        $this->assertNotEquals($plaintext, $encrypted['content']);

        $decrypted = $this->service->decrypt($encrypted['content'], $key, $encrypted['iv']);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testDeriveKey(): void
    {
        $password = "password123";
        $salt = "somesalt";

        $key1 = $this->service->deriveKey($password, $salt);
        $key2 = $this->service->deriveKey($password, $salt);

        $this->assertEquals($key1, $key2);
        $this->assertEquals(32, strlen($key1));

        // Different salt should produce different key
        $key3 = $this->service->deriveKey($password, "othersalt");
        $this->assertNotEquals($key1, $key3);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $this->expectException(\RuntimeException::class);

        $key = $this->service->generateKey();
        $wrongKey = $this->service->generateKey();
        $plaintext = "Secret";

        $encrypted = $this->service->encrypt($plaintext, $key);

        $this->service->decrypt($encrypted['content'], $wrongKey, $encrypted['iv']);
    }

    public function testDecryptTamperedContentFails(): void
    {
        $this->expectException(\RuntimeException::class);

        $key = $this->service->generateKey();
        $plaintext = "Secret";

        $encrypted = $this->service->encrypt($plaintext, $key);

        // Tamper with content (flip a bit)
        $tampered = $encrypted['content'];
        $tampered[0] = chr(ord($tampered[0]) ^ 1);

        $this->service->decrypt($tampered, $key, $encrypted['iv']);
    }
}
