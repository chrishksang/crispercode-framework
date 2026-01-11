<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Service\Email;

use CrisperCode\Service\Email\LogEmailService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogEmailService.
 */
class LogEmailServiceTest extends TestCase
{
    private LogEmailService $emailService;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->emailService = new LogEmailService($this->loggerMock);
    }

    public function testSendLogsEmailInfo(): void
    {
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Email logged (not sent)',
                $this->callback(function ($context) {
                    return $context['to'] === 'test@example.com'
                        && $context['subject'] === 'Test Subject';
                })
            );

        $result = $this->emailService->send(
            'test@example.com',
            'Test Subject',
            '<p>HTML body</p>',
            'Text body'
        );

        $this->assertTrue($result);
    }

    public function testSendReturnsTrue(): void
    {
        $result = $this->emailService->send(
            'recipient@example.com',
            'Test',
            '<p>Content</p>'
        );

        $this->assertTrue($result);
    }

    public function testGetSentEmailsReturnsAllSentEmails(): void
    {
        $this->emailService->send('a@test.com', 'Subject 1', '<p>Body 1</p>');
        $this->emailService->send('b@test.com', 'Subject 2', '<p>Body 2</p>', 'Text body');

        $sentEmails = $this->emailService->getSentEmails();

        $this->assertCount(2, $sentEmails);
        $this->assertSame('a@test.com', $sentEmails[0]['to']);
        $this->assertSame('Subject 1', $sentEmails[0]['subject']);
        $this->assertSame('b@test.com', $sentEmails[1]['to']);
        $this->assertSame('Text body', $sentEmails[1]['textBody']);
    }

    public function testGetLastEmailReturnsLastSentEmail(): void
    {
        $this->emailService->send('first@test.com', 'First', '<p>First</p>');
        $this->emailService->send('last@test.com', 'Last', '<p>Last</p>');

        $lastEmail = $this->emailService->getLastEmail();

        $this->assertNotNull($lastEmail);
        $this->assertSame('last@test.com', $lastEmail['to']);
        $this->assertSame('Last', $lastEmail['subject']);
    }

    public function testGetLastEmailReturnsNullWhenNoEmailsSent(): void
    {
        $this->assertNull($this->emailService->getLastEmail());
    }

    public function testClearSentEmailsRemovesAllEmails(): void
    {
        $this->emailService->send('test@test.com', 'Test', '<p>Test</p>');
        $this->assertCount(1, $this->emailService->getSentEmails());

        $this->emailService->clearSentEmails();

        $this->assertCount(0, $this->emailService->getSentEmails());
    }

    public function testGetSentEmailCountReturnsCorrectCount(): void
    {
        $this->assertSame(0, $this->emailService->getSentEmailCount());

        $this->emailService->send('a@test.com', 'A', '<p>A</p>');
        $this->assertSame(1, $this->emailService->getSentEmailCount());

        $this->emailService->send('b@test.com', 'B', '<p>B</p>');
        $this->assertSame(2, $this->emailService->getSentEmailCount());
    }
}
