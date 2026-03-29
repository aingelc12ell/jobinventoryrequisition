-- ============================================================
-- Phase 3: Inventory Tracking
-- ============================================================



-- -----------------------------------------------------------
-- Inventory Items (catalog)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    sku                 VARCHAR(100) NULL,
    category            VARCHAR(100) NULL,
    description         TEXT NULL,
    unit                VARCHAR(50) NOT NULL DEFAULT 'pcs',
    quantity_in_stock   INT NOT NULL DEFAULT 0,
    reorder_level       INT UNSIGNED NOT NULL DEFAULT 0,
    location            VARCHAR(255) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX uniq_sku (sku),
    INDEX idx_category (category),
    INDEX idx_name (name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Inventory Transactions (stock movement log)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id   INT UNSIGNED NOT NULL,
    type                ENUM('in', 'out', 'adjustment') NOT NULL,
    quantity            INT NOT NULL,
    reference_type      VARCHAR(50) NULL,
    reference_id        INT UNSIGNED NULL,
    notes               TEXT NULL,
    performed_by        INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
    FOREIGN KEY (performed_by) REFERENCES users(id),
    INDEX idx_item_id (inventory_item_id),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_performed_by (performed_by)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Link request_items to inventory catalog (optional FK)
-- -----------------------------------------------------------
ALTER TABLE request_items
    ADD COLUMN inventory_item_id INT UNSIGNED NULL AFTER request_id,
    ADD FOREIGN KEY fk_request_items_inventory (inventory_item_id)
        REFERENCES inventory_items(id) ON DELETE SET NULL;
