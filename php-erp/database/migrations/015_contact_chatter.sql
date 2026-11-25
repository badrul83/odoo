CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    message_type ENUM('message','note') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_messages_thread (company_id, contact_id, id),
    CONSTRAINT fk_contact_messages_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_messages_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_messages_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    activity_type ENUM('todo','call','meeting','email','upload','other') NOT NULL DEFAULT 'todo',
    summary VARCHAR(255) NOT NULL,
    due_at DATETIME NULL,
    assigned_user_id BIGINT UNSIGNED NOT NULL,
    state ENUM('planned','done','cancelled') NOT NULL DEFAULT 'planned',
    created_by BIGINT UNSIGNED NOT NULL,
    completed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_contact_activities_schedule (company_id, contact_id, state, due_at, id),
    KEY idx_contact_activities_assignee (assigned_user_id, state, due_at),
    CONSTRAINT fk_contact_activities_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_activities_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_activities_assignee FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_activities_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_activities_completer FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
