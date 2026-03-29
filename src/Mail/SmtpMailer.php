<?php

declare(strict_types=1);

namespace App\Mail;

use App\Contracts\MailerInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * SMTP mailer implementation using PHPMailer.
 *
 * This is the default mail driver. Configure SMTP credentials
 * in .env (MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS).
 */
final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly PHPMailer $mailer,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
    ): void {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearReplyTos();

            $this->mailer->isHTML(true);
            $this->mailer->setFrom($this->fromAddress, $this->fromName);
            $this->mailer->addAddress($toEmail, $toName);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $htmlBody;
            $this->mailer->AltBody = $textBody;

            $this->mailer->send();
        } catch (PHPMailerException $e) {
            throw new \RuntimeException(
                "SMTP send failed for {$toEmail}: " . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        } finally {
            $this->mailer->clearAddresses();
        }
    }
}
