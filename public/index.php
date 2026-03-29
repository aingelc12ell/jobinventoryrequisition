<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// ── Start session BEFORE anything else ─────────────────────
// Slim-CSRF's Guard checks isset($_SESSION) at construction
// time inside its DI factory. The session must already be
// active before the container resolves Guard.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (((int) ($_SERVER['SERVER_PORT'] ?? 80)) === 443);

    $sessionLifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 1800);

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'httponly'  => true,
        'secure'    => $isHttps,
        'samesite'  => 'Strict',
    ]);
    session_name($_ENV['SESSION_NAME'] ?? 'jir_session');
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Build DI container
$containerBuilder = new ContainerBuilder();

$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

// Create Slim app with PHP-DI
AppFactory::setContainer($container);
$app = AppFactory::create();

// Register middleware
$middleware = require __DIR__ . '/../config/middleware.php';
$middleware($app);

// Register routes
$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

// Run
$app->run();
