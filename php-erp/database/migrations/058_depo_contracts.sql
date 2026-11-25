CREATE TABLE IF NOT EXISTS contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    reference VARCHAR(120) NULL,
    contract_type VARCHAR(24) NOT NULL,
    partner_contact_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    terms TEXT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contract_company_reference (company_id, reference),
    KEY idx_contract_company_partner (company_id, partner_contact_id, state),
    KEY idx_contract_company_dates (company_id, state, start_date, end_date),
    CONSTRAINT fk_contract_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contract_partner FOREIGN KEY (partner_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contract_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contract_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contract_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_contract_type CHECK (contract_type IN ('customer','supplier','depo_partner')),
    CONSTRAINT chk_contract_state CHECK (state IN ('draft','active','cancelled')),
    CONSTRAINT chk_contract_dates CHECK (end_date IS NULL OR end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales_orders
    ADD COLUMN contract_id BIGINT UNSIGNED NULL AFTER partner_contact_id,
    ADD KEY idx_sales_order_contract (company_id, contract_id),
    ADD CONSTRAINT fk_sales_order_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE RESTRICT;

ALTER TABLE purchase_orders
    ADD COLUMN contract_id BIGINT UNSIGNED NULL AFTER partner_contact_id,
    ADD KEY idx_purchase_order_contract (company_id, contract_id),
    ADD CONSTRAINT fk_purchase_order_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE RESTRICT;

INSERT INTO access_permissions (permission_key, module_key, description) VALUES
    ('contracts.read', 'contracts', 'Read company contracts'),
    ('contracts.write', 'contracts', 'Create and update company contracts')
ON DUPLICATE KEY UPDATE description = VALUES(description);
