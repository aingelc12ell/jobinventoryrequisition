<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Session-based authentication middleware.
 *
 * Verifies that a valid, active user session exists. Uses a cached user
 * record in $_SESSION to avoid a database query on every request. The
 * cache is refreshed every 5 minutes (configurable via CACHE_TTL).
 */
class SessionAuthMiddleware implements MiddlewareInterface
{
    /**
     * How often (seconds) to re-fetch the user from the database
     * to pick up role changes, deactivations, etc.
     */
    private const int CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if user_id exists in the session
        if (!isset($_SESSION['user_id'])) {
            $_SESSION['flash'] = 'Please log in to continue.';

            $response = new Response();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        $userId = (int) $_SESSION['user_id'];
        $cachedAt = (int) ($_SESSION['user_cached_at'] ?? 0);
        $now = time();

        // Use cached user if fresh enough, otherwise re-fetch from DB
        if (
            isset($_SESSION['user'])
            && is_array($_SESSION['user'])
            && ($now - $cachedAt) < self::CACHE_TTL
            && (int) ($_SESSION['user']['id'] ?? 0) === $userId
        ) {
            $user = $_SESSION['user'];
        } else {
            $user = $this->userRepository->findById($userId);

            if (!$user || empty($user['is_active'])) {
                session_destroy();

                $response = new Response();
                return $response
                    ->withHeader('Location', '/login')
                    ->withStatus(302);
            }

            // Cache the user and timestamp
            $_SESSION['user'] = $user;
            $_SESSION['user_cached_at'] = $now;
        }

        // Attach user to the request as an attribute and pass to next handler
        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }
}
