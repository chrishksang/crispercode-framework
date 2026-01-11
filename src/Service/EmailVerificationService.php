<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use CrisperCode\Entity\User;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\EmailVerificationTokenManager;
use CrisperCode\Service\Email\EmailServiceInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * Service for managing email verification.
 *
 * Handles sending verification emails, validating tokens,
 * and marking users as verified.
 *
 * @package CrisperCode\Service
 */
class EmailVerificationService
{
    private EntityFactory $entityFactory;
    private EmailVerificationTokenManager $tokenManager;
    private EmailServiceInterface $emailService;
    private Twig $twig;
    private LoggerInterface $logger;

    /**
     * Application name for email subject/content.
     */
    private string $appName;

    public function __construct(
        EntityFactory $entityFactory,
        EmailVerificationTokenManager $tokenManager,
        EmailServiceInterface $emailService,
        Twig $twig,
        LoggerInterface $logger,
        string $appName
    ) {
        $this->entityFactory = $entityFactory;
        $this->tokenManager = $tokenManager;
        $this->emailService = $emailService;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->appName = $appName;
    }

    /**
     * Sends a verification email to a user.
     *
     * Creates a new verification token and sends an email with the verification link.
     *
     * @param User $user The user to send verification to.
     * @param string $baseUrl The base URL of the application.
     * @return bool True if email was sent successfully.
     */
    public function sendVerificationEmail(User $user, string $baseUrl): bool
    {
        if (!isset($user->id)) {
            $this->logger->error('Cannot send verification email: user has no ID');
            return false;
        }

        // Create verification token
        $tokenData = $this->tokenManager->createToken($user->id);

        // Build verification URL
        $verificationUrl = EmailVerificationTokenManager::formatVerificationUrl(
            $baseUrl,
            $tokenData['selector'],
            $tokenData['validator']
        );

        // Render email templates
        try {
            $htmlBody = $this->twig->fetch('email/verify-email.twig', [
                'user' => $user,
                'verificationUrl' => $verificationUrl,
                'appName' => $this->appName,
                'expiryHours' => EmailVerificationTokenManager::DEFAULT_EXPIRY_HOURS,
            ]);

            $textBody = $this->renderTextEmail($verificationUrl);
        } catch (\Exception $e) {
            $this->logger->error('Failed to render verification email template', [
                'userId' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Send email
        $subject = "Verify your email address - {$this->appName}";
        $success = $this->emailService->send($user->email, $subject, $htmlBody, $textBody);

        if ($success) {
            $this->logger->info('Verification email sent', [
                'userId' => $user->id,
                'email' => $user->email,
            ]);
        } else {
            $this->logger->error('Failed to send verification email', [
                'userId' => $user->id,
                'email' => $user->email,
            ]);
        }

        return $success;
    }

    /**
     * Verifies a token and marks the user as verified.
     *
     * @param string $selector The selector from the verification URL.
     * @param string $validator The validator from the verification URL.
     * @return User|null The verified user, or null if verification failed.
     */
    public function verifyEmail(string $selector, string $validator): ?User
    {
        // Validate token
        $token = $this->tokenManager->validateToken($selector, $validator);

        if (!$token instanceof \CrisperCode\Entity\EmailVerificationToken) {
            $this->logger->warning('Email verification failed: invalid or expired token', [
                'selector' => $selector,
            ]);
            return null;
        }

        // Load user
        /** @var User|null $user */
        $user = $this->entityFactory->findById(User::class, $token->userId);

        if ($user === null) {
            $this->logger->error('Email verification failed: user not found', [
                'userId' => $token->userId,
            ]);
            return null;
        }

        // Check if already verified
        if ($user->emailVerified) {
            $this->logger->info('Email already verified', [
                'userId' => $user->id,
            ]);
            // Mark token as used anyway to prevent reuse
            $this->tokenManager->markAsUsed($token);
            return $user;
        }

        // Mark user as verified
        $user->markEmailVerified();
        $user->save();

        // Mark token as used
        $this->tokenManager->markAsUsed($token);

        $this->logger->info('Email verified successfully', [
            'userId' => $user->id,
            'email' => $user->email,
        ]);

        return $user;
    }

    /**
     * Resends verification email to a user by email address.
     *
     * This method does not reveal whether the email exists for security reasons.
     *
     * @param string $email The email address.
     * @param string $baseUrl The base URL of the application.
     * @return bool True if email was sent (or would have been sent if user existed).
     */
    public function resendVerificationEmail(string $email, string $baseUrl): bool
    {
        $email = strtolower(trim($email));

        // Find user by email
        /** @var User|null $user */
        $user = $this->entityFactory->findOneBy(User::class, ['email' => $email]);

        if ($user === null) {
            // Don't reveal that email doesn't exist
            $this->logger->info('Resend verification requested for non-existent email', [
                'email' => $email,
            ]);
            return true;
        }

        if ($user->emailVerified) {
            // Don't reveal that email is already verified
            $this->logger->info('Resend verification requested for already verified email', [
                'email' => $email,
            ]);
            return true;
        }

        return $this->sendVerificationEmail($user, $baseUrl);
    }

    /**
     * Checks if a user needs email verification.
     *
     * @param User $user The user to check.
     * @return bool True if user needs to verify their email.
     */
    public function needsVerification(User $user): bool
    {
        return !$user->emailVerified;
    }

    /**
     * Renders a plain text version of the verification email.
     *
     * @param string $verificationUrl The verification URL.
     * @return string Plain text email content.
     */
    private function renderTextEmail(string $verificationUrl): string
    {
        return <<<TEXT
Welcome to {$this->appName}!

Please verify your email address by clicking the link below:

{$verificationUrl}

This link will expire in 24 hours.

If you did not create an account, you can safely ignore this email.

Thanks,
The {$this->appName} Team
TEXT;
    }
}
