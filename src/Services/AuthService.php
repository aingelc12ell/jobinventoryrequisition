<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TwoFactorRequiredException;
use App\Helpers\EmailHelper;
use App\Helpers\PasswordPolicy;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * Handles user authentication flows: registration, login,
 * email verification, password reset, two-factor authentication,
 * and password changes.
 */
class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TokenService $tokenService,
        private readonly EmailHelper $emailHelper,
        private readonly AuditRepository $auditRepo,
    ) {}

    /**
     * Register a new user account.
     *
     * @return array The created user record.
     * @throws RuntimeException If the email is already registered.
     */
    public function register(string $email, string $password, string $fullName): array
    {
        $existingUser = $this->userRepo->findByEmail($email);

        if ($existingUser) {
            throw new RuntimeException('A user with this email address already exists.');
        }

        // Enforce password policy
        $policyErrors = PasswordPolicy::validate($password);
        if (!empty($policyErrors)) {
            throw new RuntimeException(implode(' ', $policyErrors));
        }

        $hashedPassword = password_hash($password, PASSWORD_ARGON2ID);

        $userId = $this->userRepo->create([
            'email'         => $email,
            'password_hash' => $hashedPassword,
            'full_name'     => $fullName,
        ]);

        $user = $this->userRepo->findById($userId);

        $token = $this->tokenService->createEmailVerificationToken($userId);
        $this->emailHelper->sendVerificationEmail($email, $fullName, $token);

        $this->auditRepo->log('user.registered', $userId, 'user', $userId);

        return $user;
    }

    /**
     * Authenticate a user with email and password.
     *
     * @return array The authenticated user record.
     * @throws RuntimeException If credentials are invalid or account is deactivated.
     * @throws TwoFactorRequiredException If two-factor authentication is required.
     */
    public function login(string $email, string $password, ?string $ipAddress = null): array
    {
        $user = $this->userRepo->findByEmail($email);

        if (!$user) {
            throw new RuntimeException('Invalid credentials.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid credentials.');
        }

        if (!$user['is_active']) {
            throw new RuntimeException('Account is deactivated.');
        }

        $this->userRepo->updateLastLogin($user['id']);

        $this->auditRepo->log('user.login', $user['id'], 'user', $user['id'], null, null, $ipAddress);

        if (!empty($user['two_factor_enabled'])) {
            $code = $this->tokenService->createTwoFactorCode($user['id']);
            $this->emailHelper->sendTwoFactorCode($email, $user['full_name'], $code);

            throw new TwoFactorRequiredException($user['id']);
        }

        return $user;
    }

    /**
     * Verify a user's email address using a verification token.
     *
     * @return bool True if the token was valid and the email was verified.
     */
    public function verifyEmail(string $token): bool
    {
        $tokenRow = $this->tokenService->validateToken($token, 'email_verification');

        if (!$tokenRow) {
            return false;
        }

        $this->tokenService->consumeToken($tokenRow['id']);
        $this->userRepo->verifyEmail($tokenRow['user_id']);

        return true;
    }

    /**
     * Request a password reset email.
     *
     * Silently returns if the email is not found to avoid
     * revealing whether an account exists.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->userRepo->findByEmail($email);

        if (!$user) {
            return;
        }

        $token = $this->tokenService->createPasswordResetToken($user['id']);
        $this->emailHelper->sendPasswordResetEmail($email, $user['full_name'], $token);
    }

    /**
     * Reset a user's password using a valid reset token.
     *
     * @return bool True if the token was valid and the password was updated.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        // Enforce password policy
        $policyErrors = PasswordPolicy::validate($newPassword);
        if (!empty($policyErrors)) {
            throw new RuntimeException(implode(' ', $policyErrors));
        }

        $tokenRow = $this->tokenService->validateToken($token, 'password_reset');

        if (!$tokenRow) {
            return false;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);

        $this->userRepo->updatePassword($tokenRow['user_id'], $hashedPassword);
        $this->tokenService->consumeToken($tokenRow['id']);

        $this->auditRepo->log('user.password_reset', $tokenRow['user_id'], 'user', $tokenRow['user_id']);

        return true;
    }

    /**
     * Verify a two-factor authentication code during login.
     *
     * @return array The authenticated user record.
     * @throws RuntimeException If the code is invalid or expired.
     */
    public function verifyTwoFactor(int $userId, string $code): array
    {
        $codeRow = $this->tokenService->validateTwoFactorCode($userId, $code);

        if (!$codeRow) {
            throw new RuntimeException('Invalid or expired verification code.');
        }

        $this->tokenService->consumeTwoFactorCode($codeRow['id']);

        $user = $this->userRepo->findById($userId);

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        return $user;
    }

    /**
     * Change a user's password after verifying the current one.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->userRepo->findById($userId);

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new RuntimeException('Current password is incorrect.');
        }

        // Enforce password policy
        $policyErrors = PasswordPolicy::validate($newPassword);
        if (!empty($policyErrors)) {
            throw new RuntimeException(implode(' ', $policyErrors));
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);

        $result = $this->userRepo->updatePassword($userId, $hashedPassword);

        $this->auditRepo->log('user.password_changed', $userId, 'user', $userId);

        return $result;
    }
}
