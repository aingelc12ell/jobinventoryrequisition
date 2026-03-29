<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly DashboardService $dashboardService,
    ) {
    }

    /**
     * GET /dashboard (or /staff/dashboard, /admin/dashboard)
     *
     * Route to the appropriate role-based dashboard with analytics data.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user['id'];
        $role = $user['role'] ?? 'personnel';

        $dashboard = match ($role) {
            'admin' => $this->dashboardService->getAdminDashboard($userId),
            'staff' => $this->dashboardService->getStaffDashboard($userId),
            default => $this->dashboardService->getPersonnelDashboard($userId),
        };

        $template = match ($role) {
            'admin' => 'admin/dashboard.twig',
            'staff' => 'staff/dashboard.twig',
            default => 'personnel/dashboard.twig',
        };

        return $this->view->render($response, $template, [
            'user' => $user,
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * GET /api/dashboard/stats — JSON stats for chart updates.
     */
    public function stats(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $data = $this->dashboardService->getChartData($user['role'], (int) $user['id']);

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
