
INSERT INTO users (email, password_hash, full_name, role, is_active, email_verified_at)
VALUES ('admin@jir.local', '$argon2id$v=19$m=65536,t=4,p=1$M2lmZ2RQcTlVdTBnZGFoaw$Pi9IucWfGvfQX3j4jBK3h0XXrjqY+V/kNILEJVf3ElI', 'Default Admin', 'admin', 1, NOW())
    AS new_row
ON DUPLICATE KEY UPDATE
                     password_hash     = new_row.password_hash,
                     role              = 'admin',
                     is_active         = 1,
                     email_verified_at = NOW();
INSERT INTO settings (setting_key, setting_value) VALUES
      ('site_name', 'Job & Inventory Request System'),
      ('items_per_page', '15'),
      ('max_upload_size_mb', '10'),
      ('default_request_priority', 'medium'),
      ('notification_email_enabled', '1'),
      ('maintenance_mode', '0'),
      ('maintenance_message', 'The system is currently undergoing scheduled maintenance. Please try again later.')
    AS new_row
ON DUPLICATE KEY UPDATE setting_value = new_row.setting_value;