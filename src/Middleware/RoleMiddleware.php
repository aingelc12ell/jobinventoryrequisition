<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Role-based authorization middleware.
 *
 * Restricts access to routes based on the authenticated user's role.
 * Expects the 'user' request attribute to be set by SessionAuthMiddleware
 * before this middleware runs. If the user's role is not among the allowed
 * roles, a 403 Forbidden response is returned.
 *
 * This middleware is intended to be instantiated manually in route
 * definitions (e.g., `new RoleMiddleware(['staff', 'admin'])`) rather
 * than auto-resolved by the DI container.
 */
class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string> $allowedRoles List of roles permitted to access the route.
     */
    public function __construct(
        private readonly array $allowedRoles,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (!$user || !in_array($user['role'] ?? '', $this->allowedRoles, true)) {
            $response = new Response();
            $response->getBody()->write('Access Denied - Insufficient permissions');

            return $response->withStatus(403);
        }

        return $handler->handle($request);
    }
}
