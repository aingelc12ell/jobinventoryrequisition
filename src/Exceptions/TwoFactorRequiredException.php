<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a user with two-factor authentication enabled
 * attempts to log in and must provide a verification code.
 */
class TwoFactorRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly int $userId,
        string $message = 'Two-factor authentication required',
    ) {
        parent::__construct($message);
    }
}
