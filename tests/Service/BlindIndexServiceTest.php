<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service;

use CrisperCode\Config\FrameworkConfig;
use CrisperCode\Service\BlindIndexService;
use PHPUnit\Framework\TestCase;

class BlindIndexServiceTest extends TestCase
{
    private BlindIndexService $service;

    protected function setUp(): void
    {
        $config = new FrameworkConfig(
            rootPath: '/tmp',
            systemMasterKey: 'test_master_key_12345'
        );
        $this->service = new BlindIndexService($config);
    }

    public function testGenerateHashDeterministic(): void
    {
        $word = "Hello";
        $context = "user1";

        $hash1 = $this->service->generateHash($word, $context);
        $hash2 = $this->service->generateHash($word, $context);

        $this->assertEquals($hash1, $hash2);
    }

    public function testGenerateHashContextIsolation(): void
    {
        $word = "Hello";

        $hash1 = $this->service->generateHash($word, "user1");
        $hash2 = $this->service->generateHash($word, "user2");

        $this->assertNotEquals($hash1, $hash2);
    }

    public function testGenerateHashNormalization(): void
    {
        $context = "user1";
        $hash1 = $this->service->generateHash("Hello World!", $context);
        $hash2 = $this->service->generateHash("hello world", $context);

        $this->assertEquals($hash1, $hash2);
    }

    public function testTokenize(): void
    {
        $text = "Hello, World! This is a test.";
        $tokens = $this->service->tokenize($text);

        $expected = ['hello', 'world', 'this', 'is', 'a', 'test'];

        // Assert arrays contain same elements regardless of order, though implementation preserves order
        $this->assertEquals($expected, $tokens);
    }
}
