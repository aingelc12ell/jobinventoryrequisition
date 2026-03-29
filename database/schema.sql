-- ============================================================
-- Job & Inventory Request Management System
-- Database Schema — Phase 1: Users & Authentication
-- ============================================================


-- -----------------------------------------------------------
-- Users
-- -----------------------------------------------------------
CREATE TABLE users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(255) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    role                ENUM('personnel', 'staff', 'admin') NOT NULL DEFAULT 'personnel',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at   TIMESTAMP NULL DEFAULT NULL,
    two_factor_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at       TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX uniq_email (email)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Two-Factor Codes (email-based OTP)
-- -----------------------------------------------------------
CREATE TABLE two_factor_codes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    code        VARCHAR(10) NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_expires (user_id, expires_at)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Email Verification / Password Reset Tokens
-- -----------------------------------------------------------
CREATE TABLE user_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    type        ENUM('email_verification', 'password_reset') NOT NULL,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_type (user_id, type)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Audit Logs (Phase 6 — table created now for early logging)
-- -----------------------------------------------------------
CREATE TABLE audit_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,
    entity_type     VARCHAR(50) NULL,
    entity_id       INT UNSIGNED NULL,
    old_values      JSON NULL,
    new_values      JSON NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- System Settings (key-value)
-- -----------------------------------------------------------
CREATE TABLE settings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX uniq_key (setting_key)
) ENGINE=InnoDB;

-- -----------------------------------------------------------
-- Seed: default admin account (password: "Admin@1234")
-- The hash below is for Argon2id — regenerate on first deploy.
-- -----------------------------------------------------------
-- INSERT INTO users (email, password_hash, full_name, role, email_verified_at)
-- VALUES ('admin@example.com', '$argon2id$...', 'System Administrator', 'admin', NOW());
