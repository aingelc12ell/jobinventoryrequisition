<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * File-based rate limiting middleware.
 *
 * Tracks request counts per IP (or per user for authenticated routes)
 * using files in storage/cache/rate_limit/. Returns 429 Too Many Requests
 * when the limit is exceeded.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private string $storagePath;

    /**
     * @param int    $maxAttempts Maximum attempts within the window.
     * @param int    $windowSeconds Time window in seconds.
     * @param string $keyPrefix   Namespace for rate limit (e.g., 'login', 'api').
     */
    public function __construct(
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        private readonly string $keyPrefix = 'general',
    ) {
        $this->storagePath = dirname(__DIR__, 2) . '/storage/cache/rate_limit';

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = $this->keyPrefix . '_' . md5($ip);
        $file = $this->storagePath . '/' . $key . '.json';

        $now = time();
        $data = $this->readData($file);

        // Prune expired entries
        $data = array_filter($data, fn(int $timestamp) => $timestamp > ($now - $this->windowSeconds));

        if (count($data) >= $this->maxAttempts) {
            $retryAfter = $this->windowSeconds - ($now - min($data));

            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter,
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        // Record this attempt
        $data[] = $now;
        $this->writeData($file, $data);

        $response = $handler->handle($request);

        // Add rate limit headers
        $remaining = max(0, $this->maxAttempts - count($data));

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) ($now + $this->windowSeconds));
    }

    private function readData(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    private function writeData(string $file, array $data): void
    {
        @file_put_contents($file, json_encode(array_values($data), JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
