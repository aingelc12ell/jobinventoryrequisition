-- ============================================================
-- Phase 2: Job & Inventory Requests
-- ============================================================


-- -----------------------------------------------------------
-- Requests
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('job', 'inventory') NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT NOT NULL,
    priority        ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    status          ENUM('draft', 'submitted', 'in_review', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    submitted_by    INT UNSIGNED NOT NULL,
    assigned_to     INT UNSIGNED NULL,
    due_date        DATE NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to)  REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_type_status (type, status),
    INDEX idx_submitted_by (submitted_by),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_priority (priority)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Request Line Items (for inventory requests)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS request_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NOT NULL,
    item_name       VARCHAR(255) NOT NULL,
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,
    unit            VARCHAR(50) NULL,
    notes           TEXT NULL,

    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Request Status History (audit trail)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS request_status_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NOT NULL,
    old_status      VARCHAR(30) NULL,
    new_status      VARCHAR(30) NOT NULL,
    changed_by      INT UNSIGNED NOT NULL,
    comment         TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id),
    INDEX idx_request_id (request_id),
    INDEX idx_changed_by (changed_by)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- File Attachments
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    file_size       INT UNSIGNED NOT NULL,
    uploaded_by     INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB;
