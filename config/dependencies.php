<?php

declare(strict_types=1);

use App\Contracts\MailerInterface;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Csrf\Guard;
use Slim\Views\Twig;

return function (ContainerBuilder $containerBuilder): void {
    $settings = require __DIR__ . '/settings.php';

    $containerBuilder->addDefinitions([
        // Application settings
        'settings' => $settings,

        // PDO database connection
        PDO::class => DI\factory(function (ContainerInterface $c): PDO {
            $db = $c->get('settings')['db'];
            $charset   = $db['charset'] ?? 'utf8mb4';
            $collation = $db['collation'] ?? 'utf8mb4_general_ci';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['name'],
                $charset
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$charset}' COLLATE '{$collation}'",
            ];

            // SSL/TLS options for encrypted database connections
            $ssl = $db['ssl'] ?? [];
            if (!empty($ssl['enabled'])) {
                if (!empty($ssl['ca'])) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl['ca'];
                }
                if (!empty($ssl['cert'])) {
                    $options[PDO::MYSQL_ATTR_SSL_CERT] = $ssl['cert'];
                }
                if (!empty($ssl['key'])) {
                    $options[PDO::MYSQL_ATTR_SSL_KEY] = $ssl['key'];
                }
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $ssl['verify'] ?? true;
            }

            return new PDO($dsn, $db['user'], $db['pass'], $options);
        }),

        // Monolog logger
        LoggerInterface::class => DI\factory(function (ContainerInterface $c): Logger {
            $loggerSettings = $c->get('settings')['logger'];
            $logger = new Logger($loggerSettings['name']);
            $logger->pushHandler(
                new StreamHandler($loggerSettings['path'], $loggerSettings['level'])
            );

            return $logger;
        }),

        // Twig view
        Twig::class => DI\factory(function (ContainerInterface $c): Twig {
            $settings = $c->get('settings');
            $twigSettings = $settings['twig'];
            $isDebug = (bool) ($settings['app']['debug'] ?? false);

            return Twig::create($twigSettings['template_path'], [
                'cache' => $twigSettings['cache'],
                // In production, skip filesystem checks for compiled templates
                'auto_reload' => $isDebug,
                'debug' => $isDebug,
            ]);
        }),

        // CSRF guard — explicit storage bypasses Guard's internal
        // isset($_SESSION) check, which throws if session_start()
        // has not yet been called or failed silently.
        Guard::class => DI\factory(function (): Guard {
            // Ensure a session is active so tokens persist across requests
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            // Last-resort fallback: manually initialise the superglobal
            // so Guard never sees an undefined $_SESSION
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            if (!array_key_exists('csrf', $_SESSION)) {
                $_SESSION['csrf'] = [];
            }

            // Pass storage by reference — Guard stores &$storage internally
            // and never runs its own isset($_SESSION) branch
            $storage = &$_SESSION['csrf'];

            $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
            $guard = new Guard($responseFactory, 'csrf', $storage);
            $guard->setPersistentTokenMode(true);

            return $guard;
        }),

        // Mail driver (smtp | sendgrid)
        MailerInterface::class => DI\factory(function (ContainerInterface $c): MailerInterface {
            $mailSettings = $c->get('settings')['mail'];
            $driver = $mailSettings['driver'];

            if ($driver === 'sendgrid') {
                return new \App\Mail\SendGridMailer(
                    $mailSettings['sendgrid_api_key'],
                    $mailSettings['from_address'],
                    $mailSettings['from_name'],
                );
            }

            // Default: SMTP via PHPMailer
            $phpMailer = new PHPMailer(true);
            $phpMailer->isSMTP();
            $phpMailer->Host       = $mailSettings['host'];
            $phpMailer->Port       = $mailSettings['port'];
            $phpMailer->SMTPAuth   = true;
            $phpMailer->Username   = $mailSettings['user'];
            $phpMailer->Password   = $mailSettings['pass'];
            $phpMailer->SMTPSecure = $mailSettings['encryption'];

            return new \App\Mail\SmtpMailer(
                $phpMailer,
                $mailSettings['from_address'],
                $mailSettings['from_name'],
            );
        }),

        // Repositories (all take PDO via autowiring)
        App\Repositories\UserRepository::class       => DI\autowire(),
        App\Repositories\TokenRepository::class      => DI\autowire(),
        App\Repositories\AuditRepository::class      => DI\autowire(),
        App\Repositories\RequestRepository::class    => DI\autowire(),
        App\Repositories\AttachmentRepository::class => DI\autowire(),
        App\Repositories\InventoryRepository::class  => DI\autowire(),
        App\Repositories\SettingsRepository::class   => DI\autowire(),

        // Services — Phase 1 (Auth)
        App\Services\AuthService::class  => DI\autowire(),
        App\Services\TokenService::class => DI\autowire(),

        // Services — Phase 2 (Requests)
        App\Services\RequestValidator::class => DI\autowire(),
        App\Services\RequestService::class   => DI\factory(function (ContainerInterface $c) {
            return new App\Services\RequestService(
                $c->get(App\Repositories\RequestRepository::class),
                $c->get(App\Services\RequestValidator::class),
                $c->get(App\Events\EventDispatcher::class),
                $c->get(App\Repositories\AuditRepository::class),
                $c->get(App\Services\InventoryService::class),
            );
        }),
        App\Services\FileUploadService::class => DI\factory(function (ContainerInterface $c) {
            $settings = $c->get('settings');

            return new App\Services\FileUploadService(
                $c->get(App\Repositories\AttachmentRepository::class),
                $c->get(LoggerInterface::class),
                $settings['upload'],
            );
        }),

        // Services — Phase 3 (Inventory)
        App\Services\InventoryService::class => DI\autowire(),

        // Repositories — Phase 4 (Messaging)
        App\Repositories\MessageRepository::class => DI\autowire(),

        // Services — Phase 4 (Messaging)
        App\Services\MessageService::class => DI\factory(function (ContainerInterface $c) {
            return new App\Services\MessageService(
                $c->get(App\Repositories\MessageRepository::class),
                $c->get(App\Repositories\UserRepository::class),
                $c->get(App\Events\EventDispatcher::class),
            );
        }),

        // Services — Phase 5 (Dashboards)
        App\Services\DashboardService::class => DI\factory(function (ContainerInterface $c) {
            return new App\Services\DashboardService(
                $c->get(PDO::class),
                $c->get(App\Repositories\RequestRepository::class),
                $c->get(App\Repositories\UserRepository::class),
                $c->get(App\Repositories\InventoryRepository::class),
                $c->get(App\Repositories\MessageRepository::class),
                $c->get(App\Repositories\AuditRepository::class),
            );
        }),

        // Event system
        App\Events\EventDispatcher::class => DI\factory(function (ContainerInterface $c) {
            $dispatcher = new App\Events\EventDispatcher();

            // Request event listeners
            $requestListener = new App\Events\RequestEventListener(
                $c->get(App\Helpers\EmailHelper::class),
                $c->get(App\Repositories\UserRepository::class),
                $c->get(LoggerInterface::class),
            );

            $dispatcher->listen('request.submitted', [$requestListener, 'onRequestSubmitted']);
            $dispatcher->listen('request.status_changed', [$requestListener, 'onStatusChanged']);
            $dispatcher->listen('request.assigned', [$requestListener, 'onRequestAssigned']);

            // Inventory event listeners
            $inventoryListener = new App\Events\InventoryEventListener(
                $c->get(App\Helpers\EmailHelper::class),
                $c->get(App\Repositories\UserRepository::class),
                $c->get(LoggerInterface::class),
            );

            $dispatcher->listen('inventory.low_stock', [$inventoryListener, 'onLowStock']);

            // Message event listeners
            $messageListener = new App\Events\MessageEventListener(
                $c->get(App\Helpers\EmailHelper::class),
                $c->get(App\Repositories\UserRepository::class),
                $c->get(App\Repositories\MessageRepository::class),
                $c->get(LoggerInterface::class),
            );

            $dispatcher->listen('message.new', [$messageListener, 'onNewMessage']);

            return $dispatcher;
        }),

        // Helpers
        App\Helpers\EmailHelper::class => DI\autowire(),
    ]);
};
