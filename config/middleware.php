<?php

declare(strict_types=1);

use Slim\App;
use Slim\Csrf\Guard;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

return function (App $app): void {
    $container = $app->getContainer();
    $settings = $container->get('settings');
    $sessionSettings = $settings['session'];

    // Session is started in public/index.php (before DI container
    // builds) so that $_SESSION exists when Guard is constructed.
    $csrf = $container->get(Guard::class);

    // ── Middleware stack (LIFO: last added = outermost = runs first) ──
    //
    //  Request flow:
    //    ... → CSRF Guard (generates/validates tokens)
    //            → Pre-render (reads tokens, injects Twig globals)
    //                → TwigMiddleware (template support)
    //                    → Route handler
    //
    //  CSRF Guard MUST be outer to Pre-render so that tokens
    //  exist by the time Pre-render reads them.

    // 1. TwigMiddleware — innermost (added first)
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));

    // 2. Pre-render — reads CSRF tokens + session data into Twig globals.
    //    Runs AFTER Guard (inner to it), so getTokenName()/Value() return
    //    the tokens that Guard just generated.
    $app->add(function ($request, $handler) use ($container, $csrf) {
        /** @var Twig $twig */
        $twig = $container->get(Twig::class);
        $env = $twig->getEnvironment();

        // CSRF fields as a ready-to-use HTML string
        $nameKey = $csrf->getTokenNameKey();
        $valueKey = $csrf->getTokenValueKey();
        $name = $csrf->getTokenName() ?? '';
        $value = $csrf->getTokenValue() ?? '';

        $csrfFields = sprintf(
            '<input type="hidden" name="%s" value="%s">'
            . '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($nameKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($valueKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        $env->addGlobal('csrf_fields', $csrfFields);
        $env->addGlobal('csrf_name_key', $nameKey);
        $env->addGlobal('csrf_name', $name);
        $env->addGlobal('csrf_value_key', $valueKey);
        $env->addGlobal('csrf_value', $value);

        // Flash messages
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        $env->addGlobal('flash', $flash);

        // Old form input
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);
        $env->addGlobal('old', $old);

        // Current user from session
        $user = $_SESSION['user'] ?? null;
        $env->addGlobal('user', $user);

        // Unread message count for navbar badge (cached 60s in session)
        if ($user !== null && isset($user['id'])) {
            $cacheKey = 'unread_count';
            $cacheTtlKey = 'unread_count_at';
            $ttl = 60; // seconds

            $cachedAt = (int) ($_SESSION[$cacheTtlKey] ?? 0);
            if (isset($_SESSION[$cacheKey]) && (time() - $cachedAt) < $ttl) {
                $unreadCount = (int) $_SESSION[$cacheKey];
            } else {
                try {
                    /** @var \App\Repositories\MessageRepository $messageRepo */
                    $messageRepo = $container->get(\App\Repositories\MessageRepository::class);
                    $unreadCount = $messageRepo->countUnread((int) $user['id']);
                    $_SESSION[$cacheKey] = $unreadCount;
                    $_SESSION[$cacheTtlKey] = time();
                } catch (\Throwable $e) {
                    $unreadCount = 0;
                }
            }
            $env->addGlobal('unread_count', $unreadCount);
        } else {
            $env->addGlobal('unread_count', 0);
        }

        // App name
        $settings = $container->get('settings');
        $env->addGlobal('app_name', $settings['app']['name']);

        return $handler->handle($request);
    });

    // 3. CSRF Guard — outermost of the three (added last, runs first).
    //    Generates token on GET, validates on POST, then passes inward
    //    to pre-render which reads the now-populated tokens.
    $app->add($csrf);

    // Maintenance mode middleware — blocks non-admin users when enabled
    $app->add(new \App\Middleware\MaintenanceModeMiddleware(
        $container->get(\App\Repositories\SettingsRepository::class)
    ));

    // Session idle-timeout middleware
    // (Session is already started above; this only handles expiry.)
    $app->add(function ($request, $handler) use ($sessionSettings) {
        $idleTimeout = $sessionSettings['lifetime'];
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Your session has expired. Please log in again.'];

            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $_SESSION['last_activity'] = time();

        return $handler->handle($request);
    });

    // Security headers middleware
    $app->add(new \App\Middleware\SecurityHeadersMiddleware(
        !$settings['app']['debug']
    ));

    // Slim built-in routing middleware
    $app->addRoutingMiddleware();

    // Method override middleware (for PUT/DELETE from HTML forms via _METHOD field)
    // MUST run before routing so that it can change the method for route resolution
    $app->add(new MethodOverrideMiddleware());

    // Custom error handling with error pages
    $errorMiddleware = $app->addErrorMiddleware(
        $settings['app']['debug'],  // displayErrorDetails
        true,                        // logErrors
        true                         // logErrorDetails
    );

    // Custom error renderer for production (non-debug) mode
    if (!$settings['app']['debug']) {
        $errorHandler = $errorMiddleware->getDefaultErrorHandler();
        $errorHandler->registerErrorRenderer('text/html', new \App\Handlers\HtmlErrorRenderer(
            $container->get(Twig::class)
        ));
    }
};
