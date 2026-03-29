<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\SettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Blocks non-admin users when maintenance mode is enabled.
 *
 * Checks the 'maintenance_mode' system setting. If '1', only users
 * with the 'admin' role may proceed. All others see a maintenance page.
 * Login and logout routes are always allowed so admins can authenticate.
 */
class MaintenanceModeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SettingsRepository $settingsRepo,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $maintenanceMode = $this->settingsRepo->get('maintenance_mode', '0');

        if ($maintenanceMode !== '1') {
            return $handler->handle($request);
        }

        // Always allow login/logout routes so admins can authenticate
        $path = $request->getUri()->getPath();
        $allowedPaths = ['/login', '/logout', '/2fa/verify'];
        if (in_array($path, $allowedPaths, true)) {
            return $handler->handle($request);
        }

        // Allow admin users through
        $user = $_SESSION['user'] ?? null;
        if ($user !== null && ($user['role'] ?? '') === 'admin') {
            return $handler->handle($request);
        }

        // Return maintenance page
        $rawMessage = $this->settingsRepo->get(
            'maintenance_message',
            'The system is currently under maintenance. Please try again later.'
        );
        $message = htmlspecialchars($rawMessage ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Maintenance Mode</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
                <div class="text-center">
                    <i class="fas fa-tools fa-4x text-warning mb-4" style="font-size: 4rem;">&#9888;</i>
                    <h1 class="mb-3">Under Maintenance</h1>
                    <p class="lead text-muted">{$message}</p>
                    <a href="/login" class="btn btn-outline-secondary mt-3">Admin Login</a>
                </div>
            </div>
        </body>
        </html>
        HTML;

        $response = new Response();
        $response->getBody()->write($html);

        return $response->withStatus(503)->withHeader('Retry-After', '3600');
    }
}
