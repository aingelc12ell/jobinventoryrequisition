<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Abstraction for sending transactional emails.
 *
 * Implementations: SmtpMailer (PHPMailer), SendGridMailer (HTTP API).
 * Configured via MAIL_DRIVER in .env.
 */
interface MailerInterface
{
    /**
     * Send a single email.
     *
     * @param string $toEmail   Recipient email address.
     * @param string $toName    Recipient display name.
     * @param string $subject   Email subject line.
     * @param string $htmlBody  HTML content.
     * @param string $textBody  Plain-text fallback.
     *
     * @throws \RuntimeException If sending fails.
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
    ): void;
}
