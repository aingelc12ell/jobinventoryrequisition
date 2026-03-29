<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\EventDispatcher;
use App\Repositories\MessageRepository;
use App\Repositories\UserRepository;
use RuntimeException;

/**
 * Orchestrates messaging operations: conversation creation, replying,
 * reading, archiving, and role-based access enforcement.
 */
class MessageService
{
    public function __construct(
        private readonly MessageRepository $messageRepo,
        private readonly UserRepository $userRepo,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    /**
     * Start a new conversation.
     *
     * @param  string   $subject       Conversation subject line.
     * @param  string   $body          Initial message body.
     * @param  int      $senderId      The user starting the conversation.
     * @param  int[]    $recipientIds  User IDs to add as participants.
     * @param  int|null $requestId     Optional linked request ID.
     * @return array    The created conversation with messages.
     * @throws RuntimeException If validation or role restrictions fail.
     */
    public function startConversation(
        string $subject,
        string $body,
        int $senderId,
        array $recipientIds,
        ?int $requestId = null
    ): array {
        if (trim($subject) === '') {
            throw new RuntimeException('Subject is required.');
        }

        if (trim($body) === '') {
            throw new RuntimeException('Message body is required.');
        }

        if (empty($recipientIds)) {
            throw new RuntimeException('At least one recipient is required.');
        }

        $sender = $this->userRepo->findById($senderId);
        if ($sender === null) {
            throw new RuntimeException('Sender not found.');
        }

        // Validate role-based restrictions for each recipient
        foreach ($recipientIds as $recipientId) {
            $recipient = $this->userRepo->findById((int) $recipientId);
            if ($recipient === null) {
                throw new RuntimeException("Recipient #{$recipientId} not found.");
            }
            $this->validateMessagingPermission($sender, $recipient);
        }

        // Create conversation
        $conversationId = $this->messageRepo->createConversation($subject, $senderId, $requestId);

        // Add sender as participant
        $this->messageRepo->addParticipant($conversationId, $senderId);

        // Add recipients as participants
        foreach ($recipientIds as $recipientId) {
            $this->messageRepo->addParticipant($conversationId, (int) $recipientId);
        }

        // Add the initial message
        $this->messageRepo->addMessage($conversationId, $senderId, $body);

        // Mark as read for sender
        $this->messageRepo->markAsRead($conversationId, $senderId);

        // Dispatch event for notifications
        $this->eventDispatcher->dispatch('message.new', [
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'recipient_ids' => $recipientIds,
        ]);

        return $this->getConversation($conversationId, $senderId);
    }

    /**
     * Reply to an existing conversation.
     *
     * @param  int    $conversationId The conversation ID.
     * @param  int    $senderId       The replying user's ID.
     * @param  string $body           The reply message body.
     * @return array  The updated conversation with all messages.
     * @throws RuntimeException If validation fails or user is not a participant.
     */
    public function reply(int $conversationId, int $senderId, string $body): array
    {
        if (trim($body) === '') {
            throw new RuntimeException('Message body is required.');
        }

        if (!$this->messageRepo->isParticipant($conversationId, $senderId)) {
            throw new RuntimeException('Access denied. You are not a participant of this conversation.');
        }

        $this->messageRepo->addMessage($conversationId, $senderId, $body);
        $this->messageRepo->markAsRead($conversationId, $senderId);

        // Get other participants for notification
        $participants = $this->messageRepo->getParticipants($conversationId);
        $recipientIds = [];
        foreach ($participants as $p) {
            if ((int) $p['user_id'] !== $senderId) {
                $recipientIds[] = (int) $p['user_id'];
            }
        }

        $this->eventDispatcher->dispatch('message.new', [
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'recipient_ids' => $recipientIds,
        ]);

        return $this->getConversation($conversationId, $senderId);
    }

    /**
     * Get a conversation with all its messages.
     * Marks the conversation as read for the viewing user.
     *
     * @param  int $conversationId The conversation ID.
     * @param  int $userId         The viewing user's ID.
     * @return array The conversation with messages and participants.
     * @throws RuntimeException If not found or access denied.
     */
    public function getConversation(int $conversationId, int $userId): array
    {
        $conversation = $this->messageRepo->findConversationById($conversationId);

        if ($conversation === null) {
            throw new RuntimeException('Conversation not found.');
        }

        if (!$this->messageRepo->isParticipant($conversationId, $userId)) {
            throw new RuntimeException('Access denied. You are not a participant of this conversation.');
        }

        // Mark as read
        $this->messageRepo->markAsRead($conversationId, $userId);

        $conversation['messages'] = $this->messageRepo->getMessages($conversationId);
        $conversation['participants'] = $this->messageRepo->getParticipants($conversationId);

        return $conversation;
    }

    /**
     * Get the inbox (list of conversations) for a user.
     */
    public function getInbox(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->messageRepo->getInbox($userId, $limit, $offset);
    }

    /**
     * Get the total unread message count for a user.
     */
    public function getUnreadCount(int $userId): int
    {
        return $this->messageRepo->countUnread($userId);
    }

    /**
     * Archive a conversation for a user.
     */
    public function archiveConversation(int $conversationId, int $userId): void
    {
        if (!$this->messageRepo->isParticipant($conversationId, $userId)) {
            throw new RuntimeException('Access denied. You are not a participant of this conversation.');
        }

        $this->messageRepo->archiveConversation($conversationId, $userId);
    }

    /**
     * Get eligible recipients for a user based on role restrictions.
     *
     * Personnel can only message Staff/Admin.
     * Staff can message Personnel and other Staff/Admin.
     * Admin can message anyone.
     */
    public function getEligibleRecipients(int $userId, string $userRole): array
    {
        $allUsers = $this->userRepo->findAll([], 1000, 0);

        $eligible = [];
        foreach ($allUsers['data'] as $user) {
            if ((int) $user['id'] === $userId) {
                continue; // Skip self
            }
            if (!($user['is_active'] ?? true)) {
                continue; // Skip inactive users
            }

            if ($userRole === 'admin') {
                $eligible[] = $user;
            } elseif ($userRole === 'staff') {
                $eligible[] = $user;
            } elseif ($userRole === 'personnel') {
                // Personnel can only message staff and admin
                if (in_array($user['role'], ['staff', 'admin'], true)) {
                    $eligible[] = $user;
                }
            }
        }

        return $eligible;
    }

    /**
     * Validate that sender is allowed to message recipient based on roles.
     *
     * Rules:
     * - Personnel can only message Staff or Admin (not other Personnel)
     * - Staff can message anyone
     * - Admin can message anyone
     */
    private function validateMessagingPermission(array $sender, array $recipient): void
    {
        if ($sender['role'] === 'personnel' && $recipient['role'] === 'personnel') {
            throw new RuntimeException(
                'Personnel users can only send messages to Staff or Admin users.'
            );
        }
    }
}
