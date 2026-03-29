<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class MessageRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Create a new conversation and return its ID.
     */
    public function createConversation(string $subject, int $createdBy, ?int $requestId = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO conversations (subject, created_by, request_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$subject, $createdBy, $requestId]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a conversation by ID.
     */
    public function findConversationById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.full_name AS creator_name
             FROM conversations c
             LEFT JOIN users u ON c.created_by = u.id
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Add a participant to a conversation.
     */
    public function addParticipant(int $conversationId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Check if a user is a participant of a conversation.
     */
    public function isParticipant(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = ? AND user_id = ?'
        );
        $stmt->execute([$conversationId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get participants of a conversation with user details.
     */
    public function getParticipants(int $conversationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT cp.*, u.full_name, u.email, u.role
             FROM conversation_participants cp
             JOIN users u ON cp.user_id = u.id
             WHERE cp.conversation_id = ?'
        );
        $stmt->execute([$conversationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add a message to a conversation and return its ID.
     */
    public function addMessage(int $conversationId, int $senderId, string $body): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$conversationId, $senderId, $body]);

        // Touch conversation updated_at
        $this->db->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')
            ->execute([$conversationId]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get all messages for a conversation, chronologically, with sender names.
     */
    public function getMessages(int $conversationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, u.full_name AS sender_name, u.role AS sender_role
             FROM messages m
             JOIN users u ON m.sender_id = u.id
             WHERE m.conversation_id = ?
             ORDER BY m.created_at ASC'
        );
        $stmt->execute([$conversationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark a conversation as read for a user (update last_read_at to now).
     */
    public function markAsRead(int $conversationId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Archive a conversation for a user.
     */
    public function archiveConversation(int $conversationId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE conversation_participants SET is_archived = 1 WHERE conversation_id = ? AND user_id = ?'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Get conversations for a user (inbox) with last message preview and unread count.
     * Excludes archived conversations.
     */
    public function getInbox(int $userId, int $limit = 20, int $offset = 0): array
    {
        // Count total non-archived conversations
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM conversation_participants cp
             WHERE cp.user_id = ? AND cp.is_archived = 0'
        );
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        // Get conversations with last message preview and unread indicator
        $stmt = $this->db->prepare(
            'SELECT c.id, c.subject, c.request_id, c.created_at, c.updated_at,
                    cp.last_read_at, cp.is_archived,
                    lm.body AS last_message_body, lm.created_at AS last_message_at,
                    lm_user.full_name AS last_message_sender,
                    (SELECT COUNT(*) FROM messages m2
                     WHERE m2.conversation_id = c.id
                       AND m2.created_at > COALESCE(cp.last_read_at, \'1970-01-01\')
                       AND m2.sender_id != ?) AS unread_count,
                    r.title AS request_title
             FROM conversation_participants cp
             JOIN conversations c ON cp.conversation_id = c.id
             LEFT JOIN messages lm ON lm.id = (
                 SELECT MAX(m.id) FROM messages m WHERE m.conversation_id = c.id
             )
             LEFT JOIN users lm_user ON lm.sender_id = lm_user.id
             LEFT JOIN requests r ON c.request_id = r.id
             WHERE cp.user_id = ? AND cp.is_archived = 0
             ORDER BY c.updated_at DESC
             LIMIT ? OFFSET ?'
        );

        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Count total unread messages across all conversations for a user.
     */
    public function countUnread(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM messages m
             JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id AND cp.user_id = ?
             WHERE cp.is_archived = 0
               AND m.sender_id != ?
               AND m.created_at > COALESCE(cp.last_read_at, \'1970-01-01\')'
        );
        $stmt->execute([$userId, $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Find an existing conversation between a user and request (for request-linked messaging).
     */
    public function findConversationByRequest(int $requestId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*
             FROM conversations c
             JOIN conversation_participants cp ON c.id = cp.conversation_id
             WHERE c.request_id = ? AND cp.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Log that a notification email was sent for a conversation/user.
     */
    public function logNotification(int $conversationId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO message_notifications (conversation_id, user_id) VALUES (?, ?)'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Check if a notification was already sent for a conversation/user within the last hour.
     */
    public function wasNotifiedRecently(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM message_notifications
             WHERE conversation_id = ? AND user_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $stmt->execute([$conversationId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get users with unread messages older than a given threshold (for daily digest).
     * Returns rows with user info and unread conversation count.
     */
    public function getUsersWithUnreadMessages(int $hoursThreshold = 24): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id AS user_id, u.email, u.full_name,
                    COUNT(DISTINCT m.conversation_id) AS unread_conversations,
                    COUNT(m.id) AS unread_messages
             FROM messages m
             JOIN conversation_participants cp ON m.conversation_id = cp.conversation_id
             JOIN users u ON cp.user_id = u.id
             WHERE cp.is_archived = 0
               AND m.sender_id != cp.user_id
               AND m.created_at > COALESCE(cp.last_read_at, \'1970-01-01\')
               AND m.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY u.id, u.email, u.full_name'
        );
        $stmt->execute([$hoursThreshold]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get unread conversation summaries for a specific user (for digest email).
     */
    public function getUnreadSummaryForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.subject,
                    COUNT(m.id) AS unread_count,
                    MAX(m.created_at) AS latest_message_at
             FROM conversations c
             JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
             JOIN messages m ON m.conversation_id = c.id
             WHERE cp.is_archived = 0
               AND m.sender_id != ?
               AND m.created_at > COALESCE(cp.last_read_at, \'1970-01-01\')
             GROUP BY c.id, c.subject
             ORDER BY latest_message_at DESC'
        );
        $stmt->execute([$userId, $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
