<?php

declare(strict_types=1);

namespace CrisperCode\Service\Email;

/**
 * Interface for email sending services.
 *
 * Provides abstraction over email delivery mechanisms (SES, SMTP, etc.)
 * to allow for easy testing and swapping implementations.
 *
 * @package CrisperCode\Service\Email
 */
interface EmailServiceInterface
{
    /**
     * Sends an email.
     *
     * @param string $to Recipient email address.
     * @param string $subject Email subject line.
     * @param string $htmlBody HTML content of the email.
     * @param string|null $textBody Plain text content (optional, for multipart emails).
     * @return bool True if email was sent successfully.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool;
}
