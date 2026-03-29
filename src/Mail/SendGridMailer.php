<?php

declare(strict_types=1);

namespace App\Mail;

use App\Contracts\MailerInterface;
use RuntimeException;

/**
 * SendGrid mailer implementation using the v3 Mail Send HTTP API.
 *
 * Uses native PHP curl — no additional Composer packages required.
 * Set MAIL_DRIVER=sendgrid and SENDGRID_API_KEY in .env to activate.
 *
 * @see https://docs.sendgrid.com/api-reference/mail-send/mail-send
 */
final class SendGridMailer implements MailerInterface
{
    private const string API_URL = 'https://api.sendgrid.com/v3/mail/send';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('SENDGRID_API_KEY is required when MAIL_DRIVER is set to "sendgrid".');
        }
    }

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
    ): void {
        $payload = [
            'personalizations' => [
                [
                    'to' => [
                        [
                            'email' => $toEmail,
                            'name'  => $toName,
                        ],
                    ],
                    'subject' => $subject,
                ],
            ],
            'from' => [
                'email' => $this->fromAddress,
                'name'  => $this->fromName,
            ],
            'content' => [
                [
                    'type'  => 'text/plain',
                    'value' => $textBody,
                ],
                [
                    'type'  => 'text/html',
                    'value' => $htmlBody,
                ],
            ],
        ];

        $ch = curl_init(self::API_URL);

        if ($ch === false) {
            throw new RuntimeException('SendGrid: Failed to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("SendGrid: curl error — {$curlError}");
        }

        // SendGrid returns 202 Accepted on success
        if ($statusCode < 200 || $statusCode >= 300) {
            $body = is_string($response) ? $response : '';
            throw new RuntimeException(
                "SendGrid: API returned HTTP {$statusCode} for {$toEmail} — {$body}"
            );
        }
    }
}
