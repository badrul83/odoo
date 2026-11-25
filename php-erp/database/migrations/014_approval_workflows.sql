INSERT INTO access_permissions (permission_key, module_key, description) VALUES
    ('approvals.read', 'approvals', 'View assigned approval requests'),
    ('approvals.write', 'approvals', 'Approve or reject assigned approval steps'),
    ('sales.approve', 'sales', 'Approve sales orders assigned by approval policy'),
    ('purchase.approve', 'purchase', 'Approve purchase orders assigned by approval policy')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS approval_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    minimum_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_approval_policy_company_type (company_id, document_type),
    CONSTRAINT fk_approval_policy_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_policy_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_policy_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_approval_policy_document_type CHECK (document_type IN ('sales_order','purchase_order')),
    CONSTRAINT chk_approval_policy_minimum CHECK (minimum_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_policy_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    policy_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    approver_user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_approval_policy_step_sequence (policy_id, sequence),
    UNIQUE KEY uq_approval_policy_step_approver (policy_id, approver_user_id),
    CONSTRAINT fk_approval_policy_step_policy FOREIGN KEY (policy_id) REFERENCES approval_policies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_policy_step_approver FOREIGN KEY (approver_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(32) NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(20,6) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    document_fingerprint CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    current_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_request_document (document_type, document_id, id),
    KEY idx_approval_request_inbox (company_id, status, document_type, id),
    CONSTRAINT fk_approval_request_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_request_policy FOREIGN KEY (policy_id) REFERENCES approval_policies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_request_requester FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_request_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT,
    CONSTRAINT chk_approval_request_document_type CHECK (document_type IN ('sales_order','purchase_order')),
    CONSTRAINT chk_approval_request_amount CHECK (amount >= 0),
    CONSTRAINT chk_approval_request_status CHECK (status IN ('pending','approved','rejected','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_request_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    approver_user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    decision_comment VARCHAR(1000) NULL,
    acted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_approval_request_step_sequence (request_id, sequence),
    KEY idx_approval_request_step_assignee (approver_user_id, status, request_id),
    CONSTRAINT fk_approval_request_step_request FOREIGN KEY (request_id) REFERENCES approval_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_approval_request_step_approver FOREIGN KEY (approver_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_approval_request_step_status CHECK (status IN ('pending','approved','rejected','skipped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
