<?php

declare(strict_types=1);

namespace CrisperCode\Service\Email;

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;
use Psr\Log\LoggerInterface;

/**
 * Email service implementation using Amazon SES.
 *
 * Sends emails via the AWS Simple Email Service.
 * Requires AWS credentials and a verified sender email/domain.
 *
 * @package CrisperCode\Service\Email
 */
class SesEmailService implements EmailServiceInterface
{
    /**
     * AWS SES client.
     */
    private SesClient $sesClient;

    /**
     * Logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Sender email address.
     */
    private string $fromEmail;

    /**
     * Sender name.
     */
    private string $fromName;

    /**
     * Constructor.
     *
     * @param string $region AWS region (e.g., 'us-east-1').
     * @param string $accessKeyId AWS access key ID.
     * @param string $secretAccessKey AWS secret access key.
     * @param string $fromEmail Sender email address (must be verified in SES).
     * @param string $fromName Sender name.
     * @param LoggerInterface $logger Logger for errors and debugging.
     */
    public function __construct(
        string $region,
        string $accessKeyId,
        string $secretAccessKey,
        string $fromEmail,
        string $fromName,
        LoggerInterface $logger
    ) {
        $this->sesClient = new SesClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ]);

        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->logger = $logger;
    }

    /**
     * Sends an email via Amazon SES.
     *
     * @param string $to Recipient email address.
     * @param string $subject Email subject line.
     * @param string $htmlBody HTML content of the email.
     * @param string|null $textBody Plain text content (optional).
     * @return bool True if email was sent successfully.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $body = [
            'Html' => [
                'Charset' => 'UTF-8',
                'Data' => $htmlBody,
            ],
        ];

        if ($textBody !== null) {
            $body['Text'] = [
                'Charset' => 'UTF-8',
                'Data' => $textBody,
            ];
        }

        $source = $this->fromName !== ''
            ? sprintf('"%s" <%s>', $this->fromName, $this->fromEmail)
            : $this->fromEmail;

        try {
            $result = $this->sesClient->sendEmail([
                'Destination' => [
                    'ToAddresses' => [$to],
                ],
                'Source' => $source,
                'Message' => [
                    'Subject' => [
                        'Charset' => 'UTF-8',
                        'Data' => $subject,
                    ],
                    'Body' => $body,
                ],
            ]);

            $messageId = $result->get('MessageId');

            $this->logger->info('Email sent via SES', [
                'to' => $to,
                'subject' => $subject,
                'messageId' => $messageId,
            ]);

            return true;
        } catch (AwsException $e) {
            $this->logger->error('Failed to send email via SES', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'awsErrorCode' => $e->getAwsErrorCode(),
            ]);

            return false;
        }
    }
}
