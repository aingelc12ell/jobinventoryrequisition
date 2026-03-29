<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Enforces password strength requirements.
 *
 * Rules:
 * - Minimum 8 characters
 * - At least one uppercase letter
 * - At least one lowercase letter
 * - At least one digit
 * - Not in the common passwords list
 */
class PasswordPolicy
{
    /**
     * Top 100 most common passwords to reject.
     */
    private const array COMMON_PASSWORDS = [
        'password', '123456', '12345678', '1234', 'qwerty', '12345', 'dragon', 'pussy',
        'baseball', 'football', 'letmein', 'monkey', '696969', 'abc123', 'mustang',
        'michael', 'shadow', 'master', 'jennifer', '111111', '2000', 'jordan', 'superman',
        'harley', '1234567', 'fuckme', 'hunter', 'fuckyou', 'trustno1', 'ranger',
        'buster', 'thomas', 'tigger', 'robert', 'soccer', 'fuck', 'batman', 'test',
        'pass', 'killer', 'hockey', 'george', 'charlie', 'andrew', 'michelle', 'love',
        'sunshine', 'jessica', 'asshole', '6969', 'pepper', 'daniel', 'access', '123456789',
        '654321', 'joshua', 'maggie', 'starwars', 'silver', 'william', 'dallas', 'yankees',
        '123123', 'ashley', '666666', 'hello', 'amanda', 'orange', 'biteme', 'freedom',
        'computer', 'sexy', 'thunder', 'nicole', 'ginger', 'heather', 'hammer', 'summer',
        'corvette', 'taylor', 'fucker', 'austin', '1111', 'merlin', 'matthew', '121212',
        'golfer', 'cheese', 'princess', 'martin', 'chelsea', 'patrick', 'richard', 'diamond',
        'yellow', 'bigdog', 'secret', 'asdfgh', 'sparky', 'cowboy', 'password1', 'iloveyou',
    ];

    /**
     * Validate a password against the policy.
     *
     * @return string[] Array of validation error messages (empty if valid).
     */
    public static function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit.';
        }

        if (in_array(strtolower($password), self::COMMON_PASSWORDS, true)) {
            $errors[] = 'This password is too common. Please choose a stronger password.';
        }

        return $errors;
    }

    /**
     * Check if a password meets the policy requirements.
     */
    public static function isValid(string $password): bool
    {
        return empty(self::validate($password));
    }
}
