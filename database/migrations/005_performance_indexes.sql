-- ============================================================
-- Phase 9: Performance Indexes
-- Adds composite and covering indexes for frequently-executed
-- query patterns identified during performance audit.
-- ============================================================


-- -----------------------------------------------------------
-- requests: composite index for sorted listing queries
-- Covers: staff dashboard, request queue ORDER BY
-- -----------------------------------------------------------
ALTER TABLE requests
    ADD INDEX idx_status_created (status, created_at DESC),
    ADD INDEX idx_assigned_status (assigned_to, status),
    ADD INDEX idx_submitted_created (submitted_by, created_at DESC);

-- -----------------------------------------------------------
-- conversation_participants: composite for inbox queries
-- Covers: getInbox WHERE user_id = ? AND is_archived = 0
-- -----------------------------------------------------------
ALTER TABLE conversation_participants
    ADD INDEX idx_user_archived (user_id, is_archived);

-- -----------------------------------------------------------
-- messages: covering index for unread-count queries
-- Covers: countUnread JOIN + WHERE sender_id != ? AND created_at > ?
-- -----------------------------------------------------------
ALTER TABLE messages
    ADD INDEX idx_conv_sender_created (conversation_id, sender_id, created_at);

-- -----------------------------------------------------------
-- audit_logs: composite for user activity + date filtering
-- Covers: profile page logs, audit log filters
-- -----------------------------------------------------------
ALTER TABLE audit_logs
    ADD INDEX idx_user_created (user_id, created_at DESC);

-- -----------------------------------------------------------
-- inventory_items: composite for filtered listing
-- Covers: inventory list WHERE is_active = 1 AND category = ?
-- -----------------------------------------------------------
ALTER TABLE inventory_items
    ADD INDEX idx_active_category (is_active, category);

-- -----------------------------------------------------------
-- request_items: index on inventory_item_id for JOIN
-- Covers: inventory reservation lookups
-- -----------------------------------------------------------
ALTER TABLE request_items
    ADD INDEX idx_inventory_item (inventory_item_id);
