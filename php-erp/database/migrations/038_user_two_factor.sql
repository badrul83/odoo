ALTER TABLE users
    ADD COLUMN two_factor_secret VARBINARY(255) NULL AFTER two_factor_enabled,
    ADD COLUMN two_factor_confirmed_at DATETIME NULL AFTER two_factor_secret;

CREATE TABLE IF NOT EXISTS user_two_factor_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_two_factor_recovery_hash (code_hash),
    KEY idx_user_two_factor_recovery_owner (user_id, used_at),
    CONSTRAINT fk_user_two_factor_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
