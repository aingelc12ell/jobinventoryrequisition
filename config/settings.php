<?php

declare(strict_types=1);

$appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

return [
    'app' => [
        'name'   => $_ENV['APP_NAME'] ?? 'JobInventoryRequests',
        'env'    => $_ENV['APP_ENV'] ?? 'production',
        'debug'  => $appDebug,
        'url'    => $_ENV['APP_URL'] ?? 'http://localhost',
        'secret' => $_ENV['APP_SECRET'] ?? '',
    ],

    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? 'job_inventory_requests',
        'user'      => $_ENV['DB_USER'] ?? 'root',
        'pass'      => $_ENV['DB_PASS'] ?? '',
        'charset'   => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
        'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_general_ci',
        'ssl'       => [
            'enabled' => filter_var($_ENV['DB_SSL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ca'      => $_ENV['DB_SSL_CA'] ?? '',
            'cert'    => $_ENV['DB_SSL_CERT'] ?? '',
            'key'     => $_ENV['DB_SSL_KEY'] ?? '',
            'verify'  => filter_var($_ENV['DB_SSL_VERIFY'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    'mail' => [
        'driver'       => $_ENV['MAIL_DRIVER'] ?? 'smtp', // smtp | sendgrid
        'host'         => $_ENV['MAIL_HOST'] ?? 'smtp.mailtrap.io',
        'port'         => (int) ($_ENV['MAIL_PORT'] ?? 587),
        'user'         => $_ENV['MAIL_USER'] ?? '',
        'pass'         => $_ENV['MAIL_PASS'] ?? '',
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@example.com',
        'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'JobInventoryRequests',
        'encryption'   => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        'sendgrid_api_key' => $_ENV['SENDGRID_API_KEY'] ?? '',
    ],

    'session' => [
        'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 1800),
        'name'     => $_ENV['SESSION_NAME'] ?? 'jir_session',
    ],

    'twig' => [
        'template_path' => dirname(__DIR__) . '/templates',
        'cache'         => $appDebug ? false : dirname(__DIR__) . '/storage/cache/twig',
    ],

    'logger' => [
        'name'  => 'app',
        'path'  => dirname(__DIR__) . '/storage/logs/app.log',
        'level' => $appDebug ? \Monolog\Level::Debug : \Monolog\Level::Info,
    ],

    'upload' => [
        'max_size'      => 10485760,
        'allowed_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],
        'path' => dirname(__DIR__) . '/storage/uploads',
    ],
];
