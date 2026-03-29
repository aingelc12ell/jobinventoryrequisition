<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\TwoFactorRequiredException;
use App\Services\AuthService;
use App\Services\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $authService,
        private readonly TokenService $tokenService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /login
     */
    public function loginForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (isset($_SESSION['user_id'])) {
            return $this->redirect($response, '/dashboard');
        }

        $flash = $request->getAttribute('flash', []);

        return $this->view->render($response, 'auth/login.twig', [
            'flash' => $flash,
        ]);
    }

    /**
     * POST /login
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        try {
            $serverParams = $request->getServerParams();
            $ipAddress = $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
            $user = $this->authService->login($email, $password, $ipAddress);

            // Regenerate session ID on login to prevent fixation attacks
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_activity'] = time();

            return $this->redirect($response, '/dashboard');
        } catch (TwoFactorRequiredException $e) {
            $_SESSION['2fa_pending_user_id'] = $e->userId;

            return $this->redirect($response, '/2fa/verify');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/login');
        }
    }

    /**
     * GET /register
     */
    public function registerForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'auth/register.twig');
    }

    /**
     * POST /register
     */
    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');
        $fullName = trim((string) ($data['full_name'] ?? ''));

        $errors = [];

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($passwordConfirmation === '') {
            $errors[] = 'Password confirmation is required.';
        } elseif ($password !== $passwordConfirmation) {
            $errors[] = 'Passwords do not match.';
        }

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->flash('error', $error);
            }

            $_SESSION['old_input'] = [
                'email' => $email,
                'full_name' => $fullName,
            ];

            return $this->redirect($response, '/register');
        }

        try {
            $this->authService->register($email, $password, $fullName);

            $this->flash('success', 'Registration successful! Please check your email to verify your account.');

            return $this->redirect($response, '/login');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/register');
        }
    }

    /**
     * POST /logout
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        session_unset();
        session_destroy();

        return $this->redirect($response, '/login');
    }

    /**
     * GET /forgot-password
     */
    public function forgotPasswordForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'auth/forgot-password.twig');
    }

    /**
     * POST /forgot-password
     */
    public function forgotPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));

        $this->authService->requestPasswordReset($email);

        $this->flash('success', 'If that email exists, we\'ve sent a reset link.');

        return $this->redirect($response, '/login');
    }

    /**
     * GET /reset-password/{token}
     */
    public function resetPasswordForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getAttribute('token', '');

        return $this->view->render($response, 'auth/reset-password.twig', [
            'token' => $token,
        ]);
    }

    /**
     * POST /reset-password/{token}
     */
    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getAttribute('token', '');
        $data = (array) $request->getParsedBody();
        $password = (string) ($data['password'] ?? '');
        $passwordConfirmation = (string) ($data['password_confirmation'] ?? '');

        $errors = [];

        if ($password === '' || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($password !== $passwordConfirmation) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->flash('error', $error);
            }

            return $this->redirect($response, '/reset-password/' . $token);
        }

        try {
            $this->authService->resetPassword($token, $password);

            $this->flash('success', 'Your password has been reset successfully.');

            return $this->redirect($response, '/login');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/login');
        }
    }

    /**
     * GET /verify-email/{token}
     */
    public function verifyEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getAttribute('token', '');

        try {
            $this->authService->verifyEmail($token);

            $this->flash('success', 'Email verified!');
        } catch (\RuntimeException $e) {
            $this->flash('error', 'Invalid or expired link.');
        }

        return $this->redirect($response, '/login');
    }

    /**
     * GET /2fa/verify
     */
    public function twoFactorForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!isset($_SESSION['2fa_pending_user_id'])) {
            return $this->redirect($response, '/login');
        }

        return $this->view->render($response, 'auth/two-factor.twig');
    }

    /**
     * POST /2fa/verify
     */
    public function verifyTwoFactor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $code = trim((string) ($data['code'] ?? ''));
        $userId = $_SESSION['2fa_pending_user_id'] ?? null;

        if ($userId === null) {
            return $this->redirect($response, '/login');
        }

        try {
            $user = $this->authService->verifyTwoFactor($userId, $code);

            // Regenerate session ID after 2FA verification
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['2fa_pending_user_id']);

            return $this->redirect($response, '/dashboard');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect($response, '/2fa/verify');
        }
    }

    /**
     * Redirect helper.
     */
    private function redirect(ResponseInterface $response, string $url, int $status = 302): ResponseInterface
    {
        return $response->withHeader('Location', $url)->withStatus($status);
    }

    /**
     * Flash message helper.
     */
    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type][] = $message;
    }
}
