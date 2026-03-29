<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Contracts\MailerInterface;

/**
 * Handles sending transactional emails for authentication flows
 * (verification, password reset, two-factor codes) and generic
 * notifications.
 *
 * Transport is abstracted via MailerInterface — supports SMTP
 * (PHPMailer) and SendGrid (HTTP API). Switch with MAIL_DRIVER in .env.
 */
class EmailHelper
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    /**
     * Send an email verification link to a newly registered user.
     */
    public function sendVerificationEmail(string $toEmail, string $fullName, string $token): void
    {
        $verificationUrl = $_ENV['APP_URL'] . '/verify-email/' . $token;

        $subject  = 'Verify your email address';
        $htmlBody = $this->buildHtmlBody(
            "Hello {$fullName},",
            "<p>Thank you for registering. Please verify your email address by clicking the link below:</p>"
            . "<p><a href=\"{$verificationUrl}\">{$verificationUrl}</a></p>"
            . "<p>This link will expire in 24 hours.</p>"
        );
        $altBody = "Hello {$fullName},\n\n"
            . "Thank you for registering. Please verify your email address by visiting the following link:\n\n"
            . "{$verificationUrl}\n\n"
            . "This link will expire in 24 hours.";

        $this->send($toEmail, $fullName, $subject, $htmlBody, $altBody);
    }

    /**
     * Send a password reset link to a user.
     */
    public function sendPasswordResetEmail(string $toEmail, string $fullName, string $token): void
    {
        $resetUrl = $_ENV['APP_URL'] . '/reset-password/' . $token;

        $subject  = 'Reset your password';
        $htmlBody = $this->buildHtmlBody(
            "Hello {$fullName},",
            "<p>We received a request to reset your password. Click the link below to set a new password:</p>"
            . "<p><a href=\"{$resetUrl}\">{$resetUrl}</a></p>"
            . "<p>This link will expire in 1 hour. If you did not request a password reset, you can safely ignore this email.</p>"
        );
        $altBody = "Hello {$fullName},\n\n"
            . "We received a request to reset your password. Visit the following link to set a new password:\n\n"
            . "{$resetUrl}\n\n"
            . "This link will expire in 1 hour. If you did not request a password reset, you can safely ignore this email.";

        $this->send($toEmail, $fullName, $subject, $htmlBody, $altBody);
    }

    /**
     * Send a two-factor authentication code to a user.
     */
    public function sendTwoFactorCode(string $toEmail, string $fullName, string $code): void
    {
        $subject  = 'Your login verification code';
        $htmlBody = $this->buildHtmlBody(
            "Hello {$fullName},",
            "<p>Your login verification code is:</p>"
            . "<p style=\"font-size: 32px; font-weight: bold; letter-spacing: 4px; text-align: center; padding: 16px;\">{$code}</p>"
            . "<p>This code will expire in 15 minutes. If you did not attempt to log in, please secure your account immediately.</p>"
        );
        $altBody = "Hello {$fullName},\n\n"
            . "Your login verification code is: {$code}\n\n"
            . "This code will expire in 15 minutes. If you did not attempt to log in, please secure your account immediately.";

        $this->send($toEmail, $fullName, $subject, $htmlBody, $altBody);
    }

    /**
     * Send a generic notification email.
     *
     * @param string $toEmail     Recipient email address.
     * @param string $fullName    Recipient display name.
     * @param string $subject     Email subject line.
     * @param string $messageBody HTML content for the email body.
     */
    public function sendNotification(string $toEmail, string $fullName, string $subject, string $messageBody): void
    {
        $htmlBody = $this->buildHtmlBody(
            "Hello {$fullName},",
            $messageBody
        );
        $altBody = "Hello {$fullName},\n\n" . strip_tags($messageBody);

        $this->send($toEmail, $fullName, $subject, $htmlBody, $altBody);
    }

    /**
     * Build a simple HTML email body wrapper.
     */
    private function buildHtmlBody(string $greeting, string $content): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"></head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2>{$greeting}</h2>
                {$content}
                <hr style="margin-top: 30px; border: none; border-top: 1px solid #eee;">
                <p style="font-size: 12px; color: #999;">This is an automated message. Please do not reply to this email.</p>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Send an email via the configured mailer driver.
     *
     * Failures are logged but do not throw — email issues
     * should not block authentication flows.
     */
    private function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $altBody,
    ): void {
        try {
            $this->mailer->send($toEmail, $toName, $subject, $htmlBody, $altBody);
        } catch (\Throwable $e) {
            error_log("EmailHelper: Failed to send email to {$toEmail} — " . $e->getMessage());
        }
    }
}
