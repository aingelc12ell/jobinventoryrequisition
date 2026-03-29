#!/usr/bin/env php
<?php

/**
 * JIR — Sample Data Generator
 *
 * Creates realistic test data: users, inventory catalogue, requests at
 * various workflow stages, status history trails, threaded conversations,
 * messages, inventory transactions, and audit-log entries.
 *
 * Usage:
 *   php cli/seed.php                                   # defaults
 *   php cli/seed.php --job=30 --inventory=20 --users=8
 *   php cli/seed.php --clean                            # wipe first
 *   php cli/seed.php --help
 *
 * Options:
 *   --job=N          Number of job requests         (default: 25)
 *   --inventory=N    Number of inventory requests   (default: 25)
 *   --users=N        Users to create PER ROLE       (default: 5)
 *   --clean          Truncate all data before seeding
 *   --help           Show this message
 *
 * All generated users share the password:  Password1!
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// ================================================================
// CLI argument parsing
// ================================================================
$opts = getopt('h', ['job:', 'inventory:', 'users:','password:', 'clean', 'help']);

if (isset($opts['h']) || isset($opts['help'])) {
    echo <<<HELP
    JIR — Sample Data Generator

    Usage: php cli/seed.php [options]

    Options:
      --job=N          Number of job requests to create       (default: 25)
      --inventory=N    Number of inventory requests to create (default: 25)
      --users=N        Users to create per role               (default: 5)
      --password=N     Password of generated accounts         (default: qwerasdf)
      --clean          Truncate all tables before seeding
      --help, -h       Show this help message

    HELP;
    exit(0);
}

$jobCount       = max(1, (int) ($opts['job']       ?? 25));
$inventoryCount = max(1, (int) ($opts['inventory'] ?? 25));
$usersPerRole   = max(1, (int) ($opts['users']     ?? 5));
$password       = $opts['password'] ?? 'qwerasdf';
$doClean        = isset($opts['clean']);

// ================================================================
// Database connection
// ================================================================
$settings  = require dirname(__DIR__) . '/config/settings.php';
$db        = $settings['db'];
$charset   = $db['charset'] ?? 'utf8mb4';
$collation = $db['collation'] ?? 'utf8mb4_general_ci';
$dsn       = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$charset}";

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// SSL/TLS options for encrypted database connections
$ssl = $db['ssl'] ?? [];
if (!empty($ssl['enabled'])) {
    if (!empty($ssl['ca'])) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $ssl['ca'];
    }
    if (!empty($ssl['cert'])) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CERT] = $ssl['cert'];
    }
    if (!empty($ssl['key'])) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_KEY] = $ssl['key'];
    }
    $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $ssl['verify'] ?? true;
}

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $pdoOptions);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}
require __DIR__ . '/includes/seeder.php';
// ================================================================
// Seeder
// ================================================================
$seeder = new Seeder($pdo, $jobCount, $inventoryCount, $usersPerRole, $doClean,$password);
$seeder->run();

