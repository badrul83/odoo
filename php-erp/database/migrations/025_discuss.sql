CREATE TABLE IF NOT EXISTS discuss_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    conversation_type VARCHAR(16) NOT NULL,
    name VARCHAR(120) NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    privacy VARCHAR(16) NOT NULL DEFAULT 'private',
    direct_key CHAR(64) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_discuss_conversation_company_key (company_id, direct_key),
    UNIQUE KEY uq_discuss_conversation_id_company (id, company_id),
    KEY idx_discuss_conversation_company_type (company_id, active, conversation_type, privacy, name),
    CONSTRAINT fk_discuss_conversation_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_discuss_conversation_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_discuss_conversation_type CHECK (conversation_type IN ('channel','direct')),
    CONSTRAINT chk_discuss_conversation_privacy CHECK (privacy IN ('public','private')),
    CONSTRAINT chk_discuss_direct_key CHECK ((conversation_type = 'direct' AND direct_key IS NOT NULL) OR (conversation_type = 'channel' AND direct_key IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discuss_members (
    conversation_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_at TIMESTAMP NULL DEFAULT NULL,
    starred TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_discuss_member_user_company (user_id, company_id, archived_at, starred, conversation_id),
    CONSTRAINT fk_discuss_member_conversation_company FOREIGN KEY (conversation_id, company_id) REFERENCES discuss_conversations (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_discuss_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_discuss_member_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discuss_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(4000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_discuss_message_id_company (id, company_id),
    KEY idx_discuss_message_thread_order (conversation_id, company_id, id),
    KEY idx_discuss_message_company_created (company_id, created_at),
    CONSTRAINT fk_discuss_message_conversation_company FOREIGN KEY (conversation_id, company_id) REFERENCES discuss_conversations (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_discuss_message_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_discuss_message_body CHECK (CHAR_LENGTH(body) BETWEEN 1 AND 4000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
