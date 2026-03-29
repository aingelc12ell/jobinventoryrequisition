<?php

declare(strict_types=1);

namespace App\Events;

use App\Helpers\EmailHelper;
use App\Repositories\MessageRepository;
use App\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles side-effects for messaging events:
 * email notifications for offline users.
 */
class MessageEventListener
{
    public function __construct(
        private readonly EmailHelper $emailHelper,
        private readonly UserRepository $userRepo,
        private readonly MessageRepository $messageRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Called when a new message is sent.
     * Sends email notification to recipients who haven't read the conversation recently.
     */
    public function onNewMessage(array $payload): void
    {
        $conversationId = $payload['conversation_id'] ?? 0;
        $senderId = $payload['sender_id'] ?? 0;
        $recipientIds = $payload['recipient_ids'] ?? [];

        $sender = $this->userRepo->findById($senderId);
        $senderName = $sender['full_name'] ?? 'Unknown';

        $conversation = $this->messageRepo->findConversationById($conversationId);
        $subject = $conversation['subject'] ?? 'New Message';

        foreach ($recipientIds as $recipientId) {
            try {
                // Check deduplication: don't send if notified within the last hour
                if ($this->messageRepo->wasNotifiedRecently($conversationId, (int) $recipientId)) {
                    continue;
                }

                $recipient = $this->userRepo->findById((int) $recipientId);
                if ($recipient === null) {
                    continue;
                }

                $this->emailHelper->sendNotification(
                    $recipient['email'],
                    $recipient['full_name'],
                    "New message: {$subject}",
                    "<p>You have a new message from <strong>{$senderName}</strong> in the conversation <strong>{$subject}</strong>.</p>"
                    . "<p><a href=\"" . ($_ENV['APP_URL'] ?? '') . "/messages/{$conversationId}\">View conversation</a></p>"
                );

                $this->messageRepo->logNotification($conversationId, (int) $recipientId);

                $this->logger->info('Message notification sent', [
                    'conversation_id' => $conversationId,
                    'recipient_id' => $recipientId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send message notification', [
                    'conversation_id' => $conversationId,
                    'recipient_id' => $recipientId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
