CREATE TABLE IF NOT EXISTS inventory_accounting_settings (
    company_id BIGINT UNSIGNED NOT NULL,
    accounting_standard VARCHAR(16) NULL,
    valuation_method VARCHAR(16) NOT NULL DEFAULT 'periodic',
    closing_frequency VARCHAR(16) NOT NULL DEFAULT 'manual',
    costing_method VARCHAR(16) NOT NULL DEFAULT 'fifo',
    valuation_account_id BIGINT UNSIGNED NULL,
    variation_account_id BIGINT UNSIGNED NULL,
    journal_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    KEY idx_inventory_accounting_valuation_account (valuation_account_id),
    KEY idx_inventory_accounting_variation_account (variation_account_id),
    KEY idx_inventory_accounting_journal (journal_id),
    CONSTRAINT fk_inventory_accounting_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_accounting_valuation_account FOREIGN KEY (valuation_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_accounting_variation_account FOREIGN KEY (variation_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_accounting_journal FOREIGN KEY (journal_id) REFERENCES accounting_journals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_accounting_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_accounting_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_accounting_standard CHECK (accounting_standard IS NULL OR accounting_standard IN ('continental','anglo_saxon')),
    CONSTRAINT chk_inventory_accounting_valuation_method CHECK (valuation_method IN ('perpetual','periodic')),
    CONSTRAINT chk_inventory_accounting_closing_frequency CHECK (closing_frequency IN ('manual','daily','monthly')),
    CONSTRAINT chk_inventory_accounting_costing_method CHECK (costing_method IN ('standard','fifo','average'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_category_accounting_overrides (
    company_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    costing_method VARCHAR(16) NULL,
    valuation_method VARCHAR(16) NULL,
    valuation_account_id BIGINT UNSIGNED NULL,
    variation_account_id BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id, category_id),
    KEY idx_category_override_valuation_account (valuation_account_id),
    KEY idx_category_override_variation_account (variation_account_id),
    CONSTRAINT fk_category_override_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_category_override_category FOREIGN KEY (category_id) REFERENCES product_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_category_override_valuation_account FOREIGN KEY (valuation_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_category_override_variation_account FOREIGN KEY (variation_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_category_override_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_category_override_costing_method CHECK (costing_method IS NULL OR costing_method IN ('standard','fifo','average')),
    CONSTRAINT chk_category_override_valuation_method CHECK (valuation_method IS NULL OR valuation_method IN ('perpetual','periodic'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO product_category_accounting_overrides (company_id,category_id,costing_method,updated_by)
SELECT DISTINCT p.company_id,c.id,c.costing_method,NULL
FROM products p
JOIN product_categories c ON c.id=p.category_id
WHERE NOT EXISTS (
    SELECT 1 FROM product_category_accounting_overrides o WHERE o.company_id=p.company_id AND o.category_id=c.id
);

ALTER TABLE stock_locations
    ADD COLUMN inventory_loss_account_id BIGINT UNSIGNED NULL AFTER location_usage,
    ADD COLUMN cost_of_production_account_id BIGINT UNSIGNED NULL AFTER inventory_loss_account_id,
    ADD KEY idx_stock_location_loss_account (inventory_loss_account_id),
    ADD KEY idx_stock_location_production_account (cost_of_production_account_id),
    ADD CONSTRAINT fk_stock_location_loss_account FOREIGN KEY (inventory_loss_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_stock_location_production_account FOREIGN KEY (cost_of_production_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT;
