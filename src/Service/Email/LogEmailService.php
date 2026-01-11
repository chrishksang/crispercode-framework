<?php

declare(strict_types=1);

namespace CrisperCode\Service\Email;

use Psr\Log\LoggerInterface;

/**
 * Email service implementation that logs emails instead of sending them.
 *
 * Useful for development and testing environments where you don't want
 * to actually send emails but need to verify the email sending logic.
 *
 * @package CrisperCode\Service\Email
 */
class LogEmailService implements EmailServiceInterface
{
    /**
     * Logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Store sent emails for testing inspection.
     *
     * @var array<array{to: string, subject: string, htmlBody: string, textBody: string|null}>
     */
    private array $sentEmails = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger for email output.
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Logs an email instead of sending it.
     *
     * @param string $to Recipient email address.
     * @param string $subject Email subject line.
     * @param string $htmlBody HTML content of the email.
     * @param string|null $textBody Plain text content (optional).
     * @return bool Always returns true.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $this->sentEmails[] = [
            'to' => $to,
            'subject' => $subject,
            'htmlBody' => $htmlBody,
            'textBody' => $textBody,
        ];

        $this->logger->info('Email logged (not sent)', [
            'to' => $to,
            'subject' => $subject,
            'htmlBodyLength' => strlen($htmlBody),
            'hasTextBody' => $textBody !== null,
        ]);

        // In development, also log the full content for debugging
        $this->logger->debug('Email content', [
            'htmlBody' => $htmlBody,
            'textBody' => $textBody,
        ]);

        return true;
    }

    /**
     * Gets all emails that have been "sent" (logged).
     *
     * Useful for testing to verify emails were sent correctly.
     *
     * @return array<array{to: string, subject: string, htmlBody: string, textBody: string|null}>
     */
    public function getSentEmails(): array
    {
        return $this->sentEmails;
    }

    /**
     * Gets the last email that was "sent" (logged).
     *
     * @return array{to: string, subject: string, htmlBody: string, textBody: string|null}|null
     */
    public function getLastEmail(): ?array
    {
        if ($this->sentEmails === []) {
            return null;
        }

        return $this->sentEmails[array_key_last($this->sentEmails)];
    }

    /**
     * Clears the sent emails history.
     *
     * Useful for resetting state between tests.
     */
    public function clearSentEmails(): void
    {
        $this->sentEmails = [];
    }

    /**
     * Gets the count of sent emails.
     *
     * @return int Number of emails sent.
     */
    public function getSentEmailCount(): int
    {
        return count($this->sentEmails);
    }
}
