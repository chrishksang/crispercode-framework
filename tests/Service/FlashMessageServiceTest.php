<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service;

use CrisperCode\Enum\FlashMessageType;
use CrisperCode\Service\FlashMessageService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FlashMessageService.
 *
 * @package CrisperCode\Test\Service
 */
class FlashMessageServiceTest extends TestCase
{
    private FlashMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any existing session data
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->service = new FlashMessageService();
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        parent::tearDown();
    }

    public function testAddFlashMessage(): void
    {
        $this->service->add(FlashMessageType::SUCCESS, 'Test message');

        $this->assertTrue($this->service->has());
    }

    public function testSuccessHelper(): void
    {
        $this->service->success('Success message');

        $messages = $this->service->getAndClear();

        $this->assertCount(1, $messages);
        $this->assertSame('success', $messages[0]['type']);
        $this->assertSame('Success message', $messages[0]['message']);
        $this->assertTrue($messages[0]['dismissible']);
        $this->assertSame('bi-check-circle-fill', $messages[0]['icon']);
    }

    public function testErrorHelper(): void
    {
        $this->service->error('Error message');

        $messages = $this->service->getAndClear();

        $this->assertCount(1, $messages);
        $this->assertSame('danger', $messages[0]['type']);
        $this->assertSame('Error message', $messages[0]['message']);
        $this->assertTrue($messages[0]['dismissible']);
        $this->assertSame('bi-exclamation-triangle-fill', $messages[0]['icon']);
    }

    public function testInfoHelper(): void
    {
        $this->service->info('Info message');

        $messages = $this->service->getAndClear();

        $this->assertCount(1, $messages);
        $this->assertSame('info', $messages[0]['type']);
        $this->assertSame('Info message', $messages[0]['message']);
        $this->assertTrue($messages[0]['dismissible']);
        $this->assertSame('bi-info-circle-fill', $messages[0]['icon']);
    }

    public function testWarningHelper(): void
    {
        $this->service->warning('Warning message');

        $messages = $this->service->getAndClear();

        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0]['type']);
        $this->assertSame('Warning message', $messages[0]['message']);
        $this->assertTrue($messages[0]['dismissible']);
        $this->assertSame('bi-exclamation-circle-fill', $messages[0]['icon']);
    }

    public function testMultipleMessages(): void
    {
        $this->service->success('First message');
        $this->service->error('Second message');
        $this->service->info('Third message');

        $messages = $this->service->getAndClear();

        $this->assertCount(3, $messages);
        $this->assertSame('success', $messages[0]['type']);
        $this->assertSame('danger', $messages[1]['type']);
        $this->assertSame('info', $messages[2]['type']);
    }

    public function testGetAndClearRemovesMessages(): void
    {
        $this->service->success('Test message');

        $this->assertTrue($this->service->has());

        $messages = $this->service->getAndClear();

        $this->assertCount(1, $messages);
        $this->assertFalse($this->service->has());

        $messagesAgain = $this->service->getAndClear();
        $this->assertCount(0, $messagesAgain);
    }

    public function testClearWithoutRetrieving(): void
    {
        $this->service->success('Test message');

        $this->assertTrue($this->service->has());

        $this->service->clear();

        $this->assertFalse($this->service->has());
    }

    public function testNonDismissibleMessage(): void
    {
        $this->service->error('Critical error', false);

        $messages = $this->service->getAndClear();

        $this->assertCount(1, $messages);
        $this->assertFalse($messages[0]['dismissible']);
    }

    public function testHasReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse($this->service->has());
    }
}
