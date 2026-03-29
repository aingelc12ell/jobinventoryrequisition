<?php

declare(strict_types=1);

namespace App\Handlers;

use Slim\Error\Renderers\HtmlErrorRenderer as SlimHtmlErrorRenderer;
use Slim\Views\Twig;
use Throwable;

/**
 * Custom HTML error renderer that uses Twig templates for error pages.
 * Falls back to a simple HTML page if template rendering fails.
 */
class HtmlErrorRenderer extends SlimHtmlErrorRenderer
{
    public function __construct(
        private readonly Twig $twig,
    ) {}

    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $statusCode = 500;

        if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
            $statusCode = 404;
        } elseif ($exception instanceof \Slim\Exception\HttpForbiddenException) {
            $statusCode = 403;
        } elseif ($exception instanceof \Slim\Exception\HttpException) {
            $statusCode = $exception->getCode();
        }

        $template = match ($statusCode) {
            403 => 'errors/403.twig',
            404 => 'errors/404.twig',
            default => 'errors/500.twig',
        };

        try {
            return $this->twig->fetch($template, [
                'status_code' => $statusCode,
                'error_message' => $displayErrorDetails ? $exception->getMessage() : null,
            ]);
        } catch (Throwable $e) {
            // Fallback if template rendering fails
            return $this->renderFallback($statusCode);
        }
    }

    private function renderFallback(int $statusCode): string
    {
        $title = match ($statusCode) {
            403 => 'Access Denied',
            404 => 'Page Not Found',
            default => 'Server Error',
        };

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>{$title}</title></head>
        <body style="font-family:Arial,sans-serif;text-align:center;padding:50px;">
            <h1>{$statusCode}</h1>
            <p>{$title}</p>
            <a href="/dashboard">Go to Dashboard</a>
        </body>
        </html>
        HTML;
    }
}
