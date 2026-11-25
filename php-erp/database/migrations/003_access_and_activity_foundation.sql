ALTER TABLE users
    ADD COLUMN language_code VARCHAR(12) NOT NULL DEFAULT 'en_US' AFTER status,
    ADD COLUMN theme VARCHAR(16) NOT NULL DEFAULT 'system' AFTER language_code,
    ADD COLUMN notification_preference VARCHAR(16) NOT NULL DEFAULT 'email' AFTER theme,
    ADD COLUMN email_signature TEXT NULL AFTER notification_preference,
    ADD COLUMN live_chat_name VARCHAR(120) NULL AFTER email_signature,
    ADD COLUMN spoken_languages JSON NULL AFTER live_chat_name,
    ADD COLUMN expertise JSON NULL AFTER spoken_languages,
    ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_superuser;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_hash CHAR(64) NOT NULL,
    ip_address VARBINARY(16) NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_throttle (login_hash, ip_address, succeeded, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_user_expiry (user_id, expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_api_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    key_prefix VARCHAR(16) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_api_key_hash (key_hash),
    KEY idx_user_api_keys_owner (user_id, revoked_at),
    CONSTRAINT fk_user_api_key_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO access_permissions (permission_key, module_key, description) VALUES
    ('contacts.read', 'contacts', 'Read contacts'),
    ('contacts.write', 'contacts', 'Create and update contacts'),
    ('contacts.archive', 'contacts', 'Archive contacts'),
    ('crm.read', 'crm', 'Read CRM records'),
    ('crm.write', 'crm', 'Create and update CRM records'),
    ('crm.assign', 'crm', 'Assign CRM records'),
    ('sales.read', 'sales', 'Read sales documents'),
    ('sales.write', 'sales', 'Create and update draft sales documents'),
    ('sales.confirm', 'sales', 'Confirm and cancel sales documents'),
    ('purchase.read', 'purchase', 'Read purchase documents'),
    ('purchase.write', 'purchase', 'Create and update draft purchase documents'),
    ('purchase.confirm', 'purchase', 'Confirm and cancel purchase documents'),
    ('inventory.read', 'inventory', 'Read inventory records'),
    ('inventory.write', 'inventory', 'Create and update inventory operations'),
    ('inventory.validate', 'inventory', 'Validate inventory movements'),
    ('invoicing.read', 'invoicing', 'Read invoices, bills, and payments'),
    ('invoicing.write', 'invoicing', 'Create and update draft invoices and bills'),
    ('invoicing.post', 'invoicing', 'Post and reverse invoices and bills'),
    ('accounting.read', 'accounting', 'Read accounting records and reports'),
    ('accounting.write', 'accounting', 'Create accounting configuration and journals'),
    ('accounting.post', 'accounting', 'Post and reverse journal entries'),
    ('accounting.reconcile', 'accounting', 'Reconcile bank and cash transactions'),
    ('settings.read', 'settings', 'Read company and user configuration'),
    ('settings.write', 'settings', 'Manage company, users, and access'),
    ('discuss.read', 'discuss', 'Read authorized conversations'),
    ('discuss.write', 'discuss', 'Create messages and channels'),
    ('todo.read', 'todo', 'Read assigned tasks'),
    ('todo.write', 'todo', 'Create and update tasks'),
    ('appointments.read', 'appointments', 'Read appointments'),
    ('appointments.write', 'appointments', 'Create and update appointments'),
    ('dashboards.read', 'dashboards', 'Read authorized dashboard data')
ON DUPLICATE KEY UPDATE description = VALUES(description);
