CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    legal_name VARCHAR(190) NULL,
    tax_id VARCHAR(80) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(80) NULL,
    website VARCHAR(255) NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'BDT',
    timezone VARCHAR(80) NOT NULL DEFAULT 'Asia/Dhaka',
    logo_path VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_companies_active_name (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_branches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(80) NULL,
    street VARCHAR(190) NULL,
    street2 VARCHAR(190) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    postal_code VARCHAR(40) NULL,
    country_code CHAR(2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_company_branch_code (company_id, code),
    KEY idx_branch_parent (parent_branch_id),
    CONSTRAINT fk_branch_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_branch_parent FOREIGN KEY (parent_branch_id) REFERENCES company_branches (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    parent_contact_id BIGINT UNSIGNED NULL,
    contact_type VARCHAR(24) NOT NULL DEFAULT 'person',
    name VARCHAR(190) NOT NULL,
    job_title VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(80) NULL,
    mobile VARCHAR(80) NULL,
    website VARCHAR(255) NULL,
    tax_id VARCHAR(80) NULL,
    reference VARCHAR(120) NULL,
    language_code VARCHAR(12) NULL,
    customer TINYINT(1) NOT NULL DEFAULT 0,
    supplier TINYINT(1) NOT NULL DEFAULT 0,
    employee TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contacts_company_name (company_id, name),
    KEY idx_contacts_parent (parent_contact_id),
    KEY idx_contacts_email (email),
    KEY idx_contacts_tax_id (tax_id),
    CONSTRAINT fk_contacts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contacts_parent FOREIGN KEY (parent_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_addresses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    address_type VARCHAR(24) NOT NULL DEFAULT 'other',
    name VARCHAR(190) NULL,
    street VARCHAR(190) NULL,
    street2 VARCHAR(190) NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    postal_code VARCHAR(40) NULL,
    country_code CHAR(2) NULL,
    phone VARCHAR(80) NULL,
    email VARCHAR(190) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_addresses_type (contact_id, address_type),
    CONSTRAINT fk_contact_address_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    color SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_tag_company_name (company_id, name),
    CONSTRAINT fk_contact_tag_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_tag_links (
    contact_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, tag_id),
    CONSTRAINT fk_contact_tag_link_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_tag_link_tag FOREIGN KEY (tag_id) REFERENCES contact_tags (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    login VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'invited',
    is_superuser TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_login (login),
    UNIQUE KEY uq_users_contact (contact_id),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    system_role TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_role_company_name (company_id, name),
    CONSTRAINT fk_access_role_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(150) NOT NULL,
    module_key VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_permission_key (permission_key),
    KEY idx_access_permission_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permission_links (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permission_role FOREIGN KEY (role_id) REFERENCES access_roles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_role_permission_permission FOREIGN KEY (permission_id) REFERENCES access_permissions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role_links (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_role_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_role_role FOREIGN KEY (role_id) REFERENCES access_roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_company_access (
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    default_company TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, company_id),
    KEY idx_user_company_default (user_id, default_company),
    CONSTRAINT fk_user_company_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_company_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(100) NOT NULL,
    model_key VARCHAR(100) NOT NULL,
    record_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    request_id CHAR(36) NULL,
    ip_address VARBINARY(16) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_model_record (model_key, record_id, created_at),
    KEY idx_audit_company_created (company_id, created_at),
    KEY idx_audit_user_created (user_id, created_at),
    CONSTRAINT fk_audit_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
