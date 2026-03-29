<?php

declare(strict_types=1);

use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SessionAuthMiddleware;
use App\Repositories\UserRepository;
use App\Controllers\AuthController;
use App\Controllers\RequestController;
use App\Controllers\MessageController;
use App\Controllers\DashboardController;
use App\Controllers\StaffController;
use App\Controllers\InventoryController;
use App\Controllers\AdminController;
use App\Controllers\ProfileController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $container = $app->getContainer();

    // Resolve SessionAuthMiddleware from container (it requires UserRepository)
    $authMiddleware = new SessionAuthMiddleware($container->get(UserRepository::class));

    // ---------------------------------------------------------------
    // Public routes (no authentication required)
    // ---------------------------------------------------------------
    $app->get('/', function ($request, $response) {
        return $response->withHeader('Location', '/login')->withStatus(302);
    });
    $app->get('/login', [AuthController::class, 'loginForm']);
    $app->post('/login', [AuthController::class, 'login'])
        ->add(new RateLimitMiddleware(5, 60, 'login'));
    $app->get('/register', [AuthController::class, 'registerForm']);
    $app->post('/register', [AuthController::class, 'register'])
        ->add(new RateLimitMiddleware(5, 60, 'register'));
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/forgot-password', [AuthController::class, 'forgotPasswordForm']);
    $app->post('/forgot-password', [AuthController::class, 'forgotPassword']);
    $app->get('/reset-password/{token}', [AuthController::class, 'resetPasswordForm']);
    $app->post('/reset-password/{token}', [AuthController::class, 'resetPassword']);
    $app->get('/verify-email/{token}', [AuthController::class, 'verifyEmail']);
    $app->get('/2fa/verify', [AuthController::class, 'twoFactorForm']);
    $app->post('/2fa/verify', [AuthController::class, 'verifyTwoFactor']);

    // ---------------------------------------------------------------
    // Personnel routes (personnel, staff, admin)
    // ---------------------------------------------------------------
    $app->group('/requests', function (RouteCollectorProxy $group) {
        $group->get('', [RequestController::class, 'index']);
        $group->get('/new', [RequestController::class, 'create']);
        $group->post('', [RequestController::class, 'store']);
        $group->get('/{id}', [RequestController::class, 'show']);
        $group->get('/{id}/edit', [RequestController::class, 'edit']);
        $group->put('/{id}', [RequestController::class, 'update']);
        $group->post('/{id}/cancel', [RequestController::class, 'cancel']);
        $group->post('/{id}/submit', [RequestController::class, 'submit']);
    })
        ->add(new RoleMiddleware(['personnel', 'staff', 'admin']))
        ->add($authMiddleware);

    // Attachment download (authenticated)
    $app->get('/attachments/{id}/download', [RequestController::class, 'downloadAttachment'])
        ->add($authMiddleware);

    // ---------------------------------------------------------------
    // Messaging routes (all authenticated users)
    // ---------------------------------------------------------------
    $app->group('/messages', function (RouteCollectorProxy $group) {
        $group->get('', [MessageController::class, 'index']);
        $group->get('/new', [MessageController::class, 'create']);
        $group->post('', [MessageController::class, 'store']);
        $group->get('/{id}', [MessageController::class, 'show']);
        $group->post('/{id}/reply', [MessageController::class, 'reply']);
        $group->post('/{id}/archive', [MessageController::class, 'archive']);
    })
        ->add(new RoleMiddleware(['personnel', 'staff', 'admin']))
        ->add($authMiddleware);

    // AJAX: unread message count
    $app->get('/api/messages/unread', [MessageController::class, 'unreadCount'])
        ->add(new RateLimitMiddleware(120, 60, 'api'))
        ->add($authMiddleware);

    // ---------------------------------------------------------------
    // Staff routes (staff, admin)
    // ---------------------------------------------------------------
    $app->group('/staff', function (RouteCollectorProxy $group) {
        $group->get('/dashboard', [DashboardController::class, 'index']);
        $group->get('/requests/export', [StaffController::class, 'exportCsv']);
        $group->get('/requests', [StaffController::class, 'requests']);
        $group->get('/requests/{id}', [StaffController::class, 'showRequest']);
        $group->post('/requests/{id}/status', [StaffController::class, 'updateStatus']);
        $group->post('/requests/{id}/assign', [StaffController::class, 'assignRequest']);

        // Inventory management
        $group->get('/inventory', [InventoryController::class, 'index']);
        $group->get('/inventory/batch', [InventoryController::class, 'batchForm']);
        $group->post('/inventory/batch/stock-in', [InventoryController::class, 'batchStockIn']);
        $group->post('/inventory/batch/add', [InventoryController::class, 'batchAdd']);
        $group->get('/inventory/new', [InventoryController::class, 'create']);
        $group->post('/inventory', [InventoryController::class, 'store']);
        $group->get('/inventory/{id}', [InventoryController::class, 'show']);
        $group->get('/inventory/{id}/edit', [InventoryController::class, 'edit']);
        $group->put('/inventory/{id}', [InventoryController::class, 'update']);
        $group->post('/inventory/{id}/adjust', [InventoryController::class, 'adjust']);
    })
        ->add(new RoleMiddleware(['staff', 'admin']))
        ->add($authMiddleware);

    // API endpoints (authenticated, staff+)
    $app->get('/api/inventory/search', [InventoryController::class, 'search'])
        ->add(new RoleMiddleware(['personnel', 'staff', 'admin']))
        ->add($authMiddleware);

    // API: dashboard stats (for chart AJAX)
    $app->get('/api/dashboard/stats', [DashboardController::class, 'stats'])
        ->add($authMiddleware);

    // ---------------------------------------------------------------
    // Admin routes (admin only)
    // ---------------------------------------------------------------
    $app->group('/admin', function (RouteCollectorProxy $group) {
        $group->get('/dashboard', [DashboardController::class, 'index']);

        // User management
        $group->get('/users', [AdminController::class, 'users']);
        $group->get('/users/new', [AdminController::class, 'createUser']);
        $group->post('/users', [AdminController::class, 'storeUser']);
        $group->get('/users/{id}/edit', [AdminController::class, 'editUser']);
        $group->put('/users/{id}', [AdminController::class, 'updateUser']);
        $group->post('/users/{id}/deactivate', [AdminController::class, 'deactivateUser']);
        $group->post('/users/{id}/activate', [AdminController::class, 'activateUser']);

        // Audit logs
        $group->get('/audit-logs', [AdminController::class, 'auditLogs']);

        // System settings
        $group->get('/settings', [AdminController::class, 'settings']);
        $group->post('/settings', [AdminController::class, 'updateSettings']);
    })
        ->add(new RoleMiddleware(['admin']))
        ->add($authMiddleware);

    // ---------------------------------------------------------------
    // Profile routes (all authenticated users)
    // ---------------------------------------------------------------
    $app->get('/profile', [ProfileController::class, 'show'])
        ->add($authMiddleware);
    $app->get('/profile/{id}', [ProfileController::class, 'viewUser'])
        ->add(new RoleMiddleware(['staff', 'admin']))
        ->add($authMiddleware);

    // ---------------------------------------------------------------
    // Dashboard (role-based view)
    // ---------------------------------------------------------------
    $app->get('/dashboard', [DashboardController::class, 'index'])
        ->add($authMiddleware);
};
