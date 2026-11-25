ALTER TABLE users
    ADD COLUMN webauthn_user_handle VARBINARY(32) NULL AFTER two_factor_enabled,
    ADD UNIQUE KEY uq_users_webauthn_user_handle (webauthn_user_handle);

CREATE TABLE IF NOT EXISTS user_passkeys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    credential_id VARCHAR(1400) NOT NULL,
    credential_id_hash BINARY(32) NOT NULL,
    public_key_pem TEXT NOT NULL,
    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports JSON NULL,
    display_name VARCHAR(120) NOT NULL DEFAULT 'Passkey',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_passkeys_credential_hash (credential_id_hash),
    KEY idx_user_passkeys_owner (user_id, created_at, id),
    CONSTRAINT fk_user_passkeys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
