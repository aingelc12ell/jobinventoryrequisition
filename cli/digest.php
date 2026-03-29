<?php

/**
 * Daily Digest CLI Script
 *
 * Scans for unread messages older than 24 hours and sends per-user
 * digest emails with conversation summaries.
 *
 * Usage: php cli/digest.php
 * Recommended: Run via cron daily (e.g., 8:00 AM)
 *   0 8 * * * cd /path/to/project && php cli/digest.php >> storage/logs/digest.log 2>&1
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Build DI container
$settings = require dirname(__DIR__) . '/config/settings.php';

$containerBuilder = new \DI\ContainerBuilder();
$dependencies = require dirname(__DIR__) . '/config/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

/** @var \App\Repositories\MessageRepository $messageRepo */
$messageRepo = $container->get(\App\Repositories\MessageRepository::class);

/** @var \App\Helpers\EmailHelper $emailHelper */
$emailHelper = $container->get(\App\Helpers\EmailHelper::class);

/** @var \Psr\Log\LoggerInterface $logger */
$logger = $container->get(\Psr\Log\LoggerInterface::class);

echo "[" . date('Y-m-d H:i:s') . "] Starting daily digest...\n";

// Find users with unread messages older than 24 hours
$users = $messageRepo->getUsersWithUnreadMessages(24);

if (empty($users)) {
    echo "No unread messages to digest.\n";
    exit(0);
}

$sent = 0;

foreach ($users as $userData) {
    try {
        $summaries = $messageRepo->getUnreadSummaryForUser((int) $userData['user_id']);

        if (empty($summaries)) {
            continue;
        }

        // Build digest email body
        $bodyHtml = '<p>You have unread messages in the following conversations:</p><ul>';

        foreach ($summaries as $summary) {
            $url = ($_ENV['APP_URL'] ?? '') . '/messages/' . $summary['id'];
            $bodyHtml .= sprintf(
                '<li><a href="%s"><strong>%s</strong></a> — %d unread message%s (latest: %s)</li>',
                htmlspecialchars($url),
                htmlspecialchars($summary['subject']),
                $summary['unread_count'],
                $summary['unread_count'] > 1 ? 's' : '',
                date('M d, Y g:i A', strtotime($summary['latest_message_at']))
            );
        }

        $bodyHtml .= '</ul>';
        $bodyHtml .= '<p><a href="' . ($_ENV['APP_URL'] ?? '') . '/messages">Go to your inbox</a></p>';

        $emailHelper->sendNotification(
            $userData['email'],
            $userData['full_name'],
            'Daily Message Digest — ' . (int) $userData['unread_messages'] . ' unread message(s)',
            $bodyHtml
        );

        $sent++;
        echo "  Sent digest to {$userData['email']} ({$userData['unread_conversations']} conversations, {$userData['unread_messages']} messages)\n";

        $logger->info('Daily digest sent', [
            'user_id' => $userData['user_id'],
            'unread_conversations' => $userData['unread_conversations'],
        ]);
    } catch (\Throwable $e) {
        echo "  ERROR sending digest to {$userData['email']}: {$e->getMessage()}\n";
        $logger->error('Daily digest failed', [
            'user_id' => $userData['user_id'],
            'error' => $e->getMessage(),
        ]);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Sent {$sent} digest email(s).\n";
