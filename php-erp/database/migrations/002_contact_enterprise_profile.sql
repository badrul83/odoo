CREATE TABLE IF NOT EXISTS currencies (
    currency_code CHAR(3) NOT NULL,
    name VARCHAR(100) NOT NULL,
    symbol VARCHAR(12) NOT NULL,
    decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2,
    rounding DECIMAL(20, 8) NOT NULL DEFAULT 0.01,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (currency_code),
    KEY idx_currencies_active_name (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO currencies (currency_code, name, symbol, decimal_places, rounding, active) VALUES
    ('BDT', 'Bangladeshi Taka', '৳', 2, 0.01, 1),
    ('USD', 'US Dollar', '$', 2, 0.01, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol);

CREATE TABLE IF NOT EXISTS payment_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    due_days SMALLINT NOT NULL DEFAULT 0,
    early_discount_percent DECIMAL(7, 4) NOT NULL DEFAULT 0,
    early_discount_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_payment_terms_company_name (company_id, name),
    CONSTRAINT fk_payment_terms_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_lists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_price_lists_company_name (company_id, name),
    CONSTRAINT fk_price_lists_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_price_lists_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    direction VARCHAR(12) NOT NULL DEFAULT 'both',
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_payment_methods_company_name (company_id, name),
    CONSTRAINT fk_payment_methods_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    provider_code VARCHAR(60) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_delivery_methods_company_name (company_id, name),
    CONSTRAINT fk_delivery_methods_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incoterms (
    code VARCHAR(8) NOT NULL,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO incoterms (code, name, active) VALUES
    ('EXW', 'Ex Works', 1),
    ('FCA', 'Free Carrier', 1),
    ('CPT', 'Carriage Paid To', 1),
    ('CIP', 'Carriage and Insurance Paid To', 1),
    ('DAP', 'Delivered at Place', 1),
    ('DPU', 'Delivered at Place Unloaded', 1),
    ('DDP', 'Delivered Duty Paid', 1),
    ('FAS', 'Free Alongside Ship', 1),
    ('FOB', 'Free on Board', 1),
    ('CFR', 'Cost and Freight', 1),
    ('CIF', 'Cost, Insurance and Freight', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS fiscal_positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    country_code CHAR(2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_fiscal_positions_company_name (company_id, name),
    CONSTRAINT fk_fiscal_positions_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(190) NOT NULL,
    account_type VARCHAR(40) NOT NULL,
    allow_reconciliation TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_company_code (company_id, code),
    KEY idx_account_company_type (company_id, account_type),
    CONSTRAINT fk_account_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_levels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_partner_levels_company_sequence (company_id, sequence),
    CONSTRAINT fk_partner_levels_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    purchase_default_product_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_commission_plans_company_name (company_id, name),
    CONSTRAINT fk_commission_plans_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
    ADD COLUMN partner_level_id BIGINT UNSIGNED NULL AFTER language_code,
    ADD COLUMN area_territory VARCHAR(190) NULL AFTER reference,
    ADD COLUMN external_code VARCHAR(100) NULL AFTER area_territory,
    ADD COLUMN customer_salesperson_user_id BIGINT UNSIGNED NULL AFTER customer,
    ADD COLUMN customer_pricelist_id BIGINT UNSIGNED NULL AFTER customer_salesperson_user_id,
    ADD COLUMN customer_payment_terms_id BIGINT UNSIGNED NULL AFTER customer_pricelist_id,
    ADD COLUMN customer_payment_method_id BIGINT UNSIGNED NULL AFTER customer_payment_terms_id,
    ADD COLUMN delivery_method_id BIGINT UNSIGNED NULL AFTER customer_payment_method_id,
    ADD COLUMN customer_incoterm_code VARCHAR(8) NULL AFTER delivery_method_id,
    ADD COLUMN customer_incoterm_location VARCHAR(190) NULL AFTER customer_incoterm_code,
    ADD COLUMN avalara_code VARCHAR(100) NULL AFTER customer_incoterm_location,
    ADD COLUMN avalara_partner_code VARCHAR(100) NULL AFTER avalara_code,
    ADD COLUMN avalara_exemption VARCHAR(190) NULL AFTER avalara_partner_code,
    ADD COLUMN group_rfqs VARCHAR(16) NOT NULL DEFAULT 'on_order' AFTER supplier,
    ADD COLUMN buyer_user_id BIGINT UNSIGNED NULL AFTER group_rfqs,
    ADD COLUMN supplier_payment_terms_id BIGINT UNSIGNED NULL AFTER buyer_user_id,
    ADD COLUMN supplier_payment_method_id BIGINT UNSIGNED NULL AFTER supplier_payment_terms_id,
    ADD COLUMN supplier_currency_code CHAR(3) NULL AFTER supplier_payment_method_id,
    ADD COLUMN supplier_1099_box_code VARCHAR(40) NULL AFTER supplier_currency_code,
    ADD COLUMN receipt_reminder TINYINT(1) NOT NULL DEFAULT 0 AFTER supplier_1099_box_code,
    ADD COLUMN supplier_incoterm_code VARCHAR(8) NULL AFTER receipt_reminder,
    ADD COLUMN supplier_incoterm_location VARCHAR(190) NULL AFTER supplier_incoterm_code,
    ADD COLUMN receivable_account_id BIGINT UNSIGNED NULL AFTER supplier_incoterm_location,
    ADD COLUMN payable_account_id BIGINT UNSIGNED NULL AFTER receivable_account_id,
    ADD COLUMN auto_post_bills VARCHAR(20) NOT NULL DEFAULT 'never' AFTER payable_account_id,
    ADD COLUMN peppol_id VARCHAR(100) NULL AFTER auto_post_bills,
    ADD COLUMN peppol_status VARCHAR(24) NOT NULL DEFAULT 'unchecked' AFTER peppol_id,
    ADD COLUMN fiscal_position_id BIGINT UNSIGNED NULL AFTER peppol_status,
    ADD COLUMN commission_plan_id BIGINT UNSIGNED NULL AFTER fiscal_position_id,
    ADD COLUMN activation_stage VARCHAR(80) NULL AFTER commission_plan_id,
    ADD COLUMN level_weight DECIMAL(12, 4) NOT NULL DEFAULT 0 AFTER activation_stage,
    ADD COLUMN latest_review_date DATE NULL AFTER level_weight,
    ADD COLUMN next_review_date DATE NULL AFTER latest_review_date,
    ADD COLUMN partnership_date DATE NULL AFTER next_review_date,
    ADD COLUMN latitude DECIMAL(10, 7) NULL AFTER partnership_date,
    ADD COLUMN longitude DECIMAL(10, 7) NULL AFTER latitude,
    ADD COLUMN legal_form VARCHAR(100) NULL AFTER longitude,
    ADD COLUMN formation_date DATE NULL AFTER legal_form,
    ADD COLUMN internal_notes MEDIUMTEXT NULL AFTER formation_date,
    ADD KEY idx_contacts_customer_salesperson (customer_salesperson_user_id),
    ADD KEY idx_contacts_buyer (buyer_user_id),
    ADD CONSTRAINT fk_contact_partner_level FOREIGN KEY (partner_level_id) REFERENCES partner_levels (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_salesperson FOREIGN KEY (customer_salesperson_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_customer_pricelist FOREIGN KEY (customer_pricelist_id) REFERENCES price_lists (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_customer_payment_terms FOREIGN KEY (customer_payment_terms_id) REFERENCES payment_terms (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_customer_payment_method FOREIGN KEY (customer_payment_method_id) REFERENCES payment_methods (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_delivery_method FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_customer_incoterm FOREIGN KEY (customer_incoterm_code) REFERENCES incoterms (code) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_buyer FOREIGN KEY (buyer_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_supplier_payment_terms FOREIGN KEY (supplier_payment_terms_id) REFERENCES payment_terms (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_supplier_payment_method FOREIGN KEY (supplier_payment_method_id) REFERENCES payment_methods (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_supplier_currency FOREIGN KEY (supplier_currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_supplier_incoterm FOREIGN KEY (supplier_incoterm_code) REFERENCES incoterms (code) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_receivable_account FOREIGN KEY (receivable_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_payable_account FOREIGN KEY (payable_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_fiscal_position FOREIGN KEY (fiscal_position_id) REFERENCES fiscal_positions (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_contact_commission_plan FOREIGN KEY (commission_plan_id) REFERENCES commission_plans (id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS contact_bank_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    bank_name VARCHAR(190) NOT NULL,
    account_holder VARCHAR(190) NULL,
    account_number_ciphertext VARBINARY(1024) NULL,
    account_number_last_four CHAR(4) NULL,
    routing_number_ciphertext VARBINARY(1024) NULL,
    routing_number_last_four CHAR(4) NULL,
    iban_ciphertext VARBINARY(1024) NULL,
    iban_last_four CHAR(4) NULL,
    currency_code CHAR(3) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_bank_contact (contact_id, active),
    CONSTRAINT fk_contact_bank_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_bank_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_ubo_owners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_contact_id BIGINT UNSIGNED NOT NULL,
    owner_contact_id BIGINT UNSIGNED NOT NULL,
    control_method VARCHAR(120) NULL,
    ownership_percent DECIMAL(7, 4) NULL,
    voting_rights_percent DECIMAL(7, 4) NULL,
    control_start_date DATE NULL,
    control_end_date DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_company_ubo_owner (company_contact_id, owner_contact_id),
    CONSTRAINT fk_ubo_company_contact FOREIGN KEY (company_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ubo_owner_contact FOREIGN KEY (owner_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_partner_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id BIGINT UNSIGNED NULL,
    review_date DATE NOT NULL,
    next_review_date DATE NULL,
    rating VARCHAR(40) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_reviews_contact_date (contact_id, review_date),
    CONSTRAINT fk_contact_review_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_review_user FOREIGN KEY (reviewer_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO access_permissions (permission_key, module_key, description) VALUES
    ('contacts.bank.read', 'contacts', 'Read bank account details'),
    ('contacts.bank.write', 'contacts', 'Create and update bank account details'),
    ('contacts.ubo.read', 'contacts', 'Read beneficial ownership details'),
    ('contacts.ubo.write', 'contacts', 'Create and update beneficial ownership details')
ON DUPLICATE KEY UPDATE description = VALUES(description);
