<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AuditRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class AdminController
{
    public function __construct(
        private readonly Twig $view,
        private readonly UserRepository $userRepo,
        private readonly AuditRepository $auditRepo,
        private readonly SettingsRepository $settingsRepo,
    ) {
    }

    // ── User Management ─────────────────────────────────────────────

    /**
     * GET /admin/users — List all users with filters and pagination.
     */
    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = array_filter([
            'role' => $params['role'] ?? null,
            'is_active' => isset($params['is_active']) ? $params['is_active'] : null,
            'search' => $params['search'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        $result = $this->userRepo->findAll($filters, $limit, $offset);
        $totalPages = (int) ceil($result['total'] / $limit);

        return $this->view->render($response, 'admin/users/index.twig', [
            'users' => $result['data'],
            'total' => $result['total'],
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
        ]);
    }

    /**
     * GET /admin/users/new — Show create user form.
     */
    public function createUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'admin/users/form.twig', [
            'form_user' => null,
            'mode' => 'create',
        ]);
    }

    /**
     * POST /admin/users — Store a new user.
     */
    public function storeUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        // Validate
        $errors = $this->validateUserForm($data, null);
        if (!empty($errors)) {
            $this->flash('danger', implode(' ', $errors));
            $_SESSION['old_input'] = $data;

            return $this->redirect($response, '/admin/users/new');
        }

        $userId = $this->userRepo->create([
            'email' => trim($data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_ARGON2ID),
            'full_name' => trim($data['full_name']),
            'role' => $data['role'] ?? 'personnel',
        ]);

        // If admin chose to verify email immediately
        if (!empty($data['email_verified'])) {
            $this->userRepo->verifyEmail($userId);
        }

        $this->auditRepo->log(
            'user.created',
            (int) $user['id'],
            'user',
            $userId,
            null,
            ['email' => $data['email'], 'role' => $data['role'] ?? 'personnel'],
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        $this->flash('success', 'User created successfully.');

        return $this->redirect($response, '/admin/users');
    }

    /**
     * GET /admin/users/{id}/edit — Show edit user form.
     */
    public function editUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $targetId = (int) $args['id'];
        $targetUser = $this->userRepo->findById($targetId);

        if ($targetUser === null) {
            $this->flash('danger', 'User not found.');

            return $this->redirect($response, '/admin/users');
        }

        // Fetch audit logs for this user (actions performed by them)
        $logsResult = $this->auditRepo->findAll(['user_id' => $targetId], 20, 0);

        return $this->view->render($response, 'admin/users/form.twig', [
            'form_user' => $targetUser,
            'mode' => 'edit',
            'user_logs' => $logsResult['data'],
        ]);
    }

    /**
     * PUT /admin/users/{id} — Update an existing user.
     */
    public function updateUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $admin = $request->getAttribute('user');
        $targetId = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $targetUser = $this->userRepo->findById($targetId);
        if ($targetUser === null) {
            $this->flash('danger', 'User not found.');

            return $this->redirect($response, '/admin/users');
        }

        // Role change safeguard: admin cannot demote themselves
        $newRole = $data['role'] ?? $targetUser['role'];
        if ($targetId === (int) $admin['id'] && $newRole !== 'admin') {
            $this->flash('danger', 'You cannot change your own role. Another admin must do this.');
            $_SESSION['old_input'] = $data;

            return $this->redirect($response, '/admin/users/' . $targetId . '/edit');
        }

        // Password re-entry required for role changes
        if ($newRole !== $targetUser['role']) {
            if (empty($data['admin_password'])) {
                $this->flash('danger', 'Your password is required to change a user\'s role.');
                $_SESSION['old_input'] = $data;

                return $this->redirect($response, '/admin/users/' . $targetId . '/edit');
            }

            if (!password_verify($data['admin_password'], $admin['password_hash'] ?? '')) {
                $this->flash('danger', 'Incorrect admin password. Role change denied.');
                $_SESSION['old_input'] = $data;

                return $this->redirect($response, '/admin/users/' . $targetId . '/edit');
            }
        }

        // Validate
        $errors = $this->validateUserForm($data, $targetId);
        if (!empty($errors)) {
            $this->flash('danger', implode(' ', $errors));
            $_SESSION['old_input'] = $data;

            return $this->redirect($response, '/admin/users/' . $targetId . '/edit');
        }

        $oldValues = [
            'full_name' => $targetUser['full_name'],
            'email' => $targetUser['email'],
            'role' => $targetUser['role'],
            'is_active' => $targetUser['is_active'],
        ];

        // Update profile fields
        $this->userRepo->update($targetId, [
            'full_name' => trim($data['full_name']),
            'email' => trim($data['email']),
            'role' => $newRole,
            'is_active' => isset($data['is_active']) ? 1 : 0,
        ]);

        // Update password if provided
        if (!empty($data['password'])) {
            $this->userRepo->updatePassword($targetId, password_hash($data['password'], PASSWORD_ARGON2ID));
        }

        $newValues = [
            'full_name' => trim($data['full_name']),
            'email' => trim($data['email']),
            'role' => $newRole,
            'is_active' => isset($data['is_active']) ? 1 : 0,
        ];

        $this->auditRepo->log(
            'user.updated',
            (int) $admin['id'],
            'user',
            $targetId,
            $oldValues,
            $newValues,
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        $this->flash('success', 'User updated successfully.');

        return $this->redirect($response, '/admin/users');
    }

    /**
     * POST /admin/users/{id}/deactivate — Deactivate a user.
     */
    public function deactivateUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $admin = $request->getAttribute('user');
        $targetId = (int) $args['id'];

        if ($targetId === (int) $admin['id']) {
            $this->flash('danger', 'You cannot deactivate your own account.');

            return $this->redirect($response, '/admin/users');
        }

        $targetUser = $this->userRepo->findById($targetId);
        if ($targetUser === null) {
            $this->flash('danger', 'User not found.');

            return $this->redirect($response, '/admin/users');
        }

        // Reassign open requests to nobody (unassign)
        $reassigned = $this->userRepo->reassignRequests($targetId, null);

        $this->userRepo->deactivate($targetId);

        $this->auditRepo->log(
            'user.deactivated',
            (int) $admin['id'],
            'user',
            $targetId,
            ['is_active' => 1],
            ['is_active' => 0, 'reassigned_requests' => $reassigned],
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        $msg = "User deactivated.";
        if ($reassigned > 0) {
            $msg .= " {$reassigned} open request(s) were unassigned.";
        }
        $this->flash('success', $msg);

        return $this->redirect($response, '/admin/users');
    }

    /**
     * POST /admin/users/{id}/activate — Reactivate a user.
     */
    public function activateUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $admin = $request->getAttribute('user');
        $targetId = (int) $args['id'];

        $targetUser = $this->userRepo->findById($targetId);
        if ($targetUser === null) {
            $this->flash('danger', 'User not found.');

            return $this->redirect($response, '/admin/users');
        }

        $this->userRepo->activate($targetId);

        $this->auditRepo->log(
            'user.activated',
            (int) $admin['id'],
            'user',
            $targetId,
            ['is_active' => 0],
            ['is_active' => 1],
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        $this->flash('success', 'User reactivated.');

        return $this->redirect($response, '/admin/users');
    }

    // ── Audit Log Viewer ────────────────────────────────────────────

    /**
     * GET /admin/audit-logs — View audit log with filters.
     */
    public function auditLogs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $filters = array_filter([
            'user_id' => !empty($params['user_id']) ? (int) $params['user_id'] : null,
            'action' => $params['action'] ?? null,
            'entity_type' => $params['entity_type'] ?? null,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        $result = $this->auditRepo->findAll($filters, $limit, $offset);
        $totalPages = (int) ceil($result['total'] / $limit);

        return $this->view->render($response, 'admin/audit-logs.twig', [
            'logs' => $result['data'],
            'total' => $result['total'],
            'current_page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
        ]);
    }

    // ── System Settings ─────────────────────────────────────────────

    /**
     * GET /admin/settings — Show system settings form.
     */
    public function settings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $settings = $this->settingsRepo->getAll();

        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settings,
        ]);
    }

    /**
     * POST /admin/settings — Update system settings.
     */
    public function updateSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $admin = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $settingKeys = [
            'site_name',
            'notification_email_enabled',
            'default_request_priority',
            'maintenance_mode',
            'maintenance_message',
            'max_upload_size_mb',
            'items_per_page',
        ];

        $oldSettings = $this->settingsRepo->getAll();
        $newSettings = [];

        foreach ($settingKeys as $key) {
            $newSettings[$key] = $data[$key] ?? '';
        }

        // Checkbox fields — treat absence as '0'
        foreach (['notification_email_enabled', 'maintenance_mode'] as $boolKey) {
            $newSettings[$boolKey] = isset($data[$boolKey]) ? '1' : '0';
        }

        $this->settingsRepo->setMany($newSettings);

        $this->auditRepo->log(
            'settings.updated',
            (int) $admin['id'],
            'settings',
            null,
            $oldSettings,
            $newSettings,
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        $this->flash('success', 'System settings updated.');

        return $this->redirect($response, '/admin/settings');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Validate user creation/edit form data.
     */
    private function validateUserForm(array $data, ?int $existingUserId): array
    {
        $errors = [];

        if (empty(trim($data['full_name'] ?? ''))) {
            $errors[] = 'Full name is required.';
        }

        $email = trim($data['email'] ?? '');
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } else {
            // Check email uniqueness
            $existing = $this->userRepo->findByEmail($email);
            if ($existing && (int) $existing['id'] !== $existingUserId) {
                $errors[] = 'This email is already in use.';
            }
        }

        $role = $data['role'] ?? '';
        if (!in_array($role, ['personnel', 'staff', 'admin'], true)) {
            $errors[] = 'Invalid role selected.';
        }

        // Password required for new users
        if ($existingUserId === null) {
            if (empty($data['password'])) {
                $errors[] = 'Password is required.';
            } elseif (strlen($data['password']) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
        } elseif (!empty($data['password']) && strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        return $errors;
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    private function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
