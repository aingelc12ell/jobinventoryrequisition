<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TokenRepository;
use DateTime;

/**
 * Handles secure token generation for email verification,
 * password reset links, and two-factor authentication codes.
 */
class TokenService
{
    public function __construct(
        private readonly TokenRepository $tokenRepo,
    ) {}

    /**
     * Generate a cryptographically secure random 64-character hex string.
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash a token using HMAC-SHA256 with the application secret.
     */
    public function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, $_ENV['APP_SECRET']);
    }

    /**
     * Create an email verification token (24h expiry).
     * Returns the RAW token for inclusion in the email link.
     */
    public function createEmailVerificationToken(int $userId): string
    {
        $rawToken = $this->generateToken();
        $hashedToken = $this->hashToken($rawToken);

        $expiresAt = new DateTime('+24 hours');
        $this->tokenRepo->create($userId, $hashedToken, 'email_verification', $expiresAt);

        return $rawToken;
    }

    /**
     * Create a password reset token (1h expiry).
     * Returns the RAW token for inclusion in the email link.
     */
    public function createPasswordResetToken(int $userId): string
    {
        $rawToken = $this->generateToken();
        $hashedToken = $this->hashToken($rawToken);

        $expiresAt = new DateTime('+1 hour');
        $this->tokenRepo->create($userId, $hashedToken, 'password_reset', $expiresAt);

        return $rawToken;
    }

    /**
     * Validate a raw token against stored hashes.
     *
     * @return array|null The token row if valid, null otherwise.
     */
    public function validateToken(string $rawToken, string $type): ?array
    {
        $hashedToken = $this->hashToken($rawToken);

        return $this->tokenRepo->findValidToken($hashedToken, $type);
    }

    /**
     * Mark a token as used/consumed.
     */
    public function consumeToken(int $tokenId): void
    {
        $this->tokenRepo->markUsed($tokenId);
    }

    /**
     * Generate a random 6-digit numeric code for two-factor authentication.
     */
    public function generateTwoFactorCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Create and store a 2FA code (15min expiry).
     *
     * @return string The generated 6-digit code.
     */
    public function createTwoFactorCode(int $userId): string
    {
        $code = $this->generateTwoFactorCode();

        $expiresAt = new DateTime('+15 minutes');
        $this->tokenRepo->saveTwoFactorCode($userId, $code, $expiresAt);

        return $code;
    }

    /**
     * Validate a two-factor authentication code.
     *
     * @return array|null The code row if valid, null otherwise.
     */
    public function validateTwoFactorCode(int $userId, string $code): ?array
    {
        return $this->tokenRepo->findValidTwoFactorCode($userId, $code);
    }

    /**
     * Mark a two-factor authentication code as used.
     */
    public function consumeTwoFactorCode(int $id): void
    {
        $this->tokenRepo->markTwoFactorUsed($id);
    }
}
