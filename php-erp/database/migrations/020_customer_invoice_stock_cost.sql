ALTER TABLE customer_invoice_lines
    ADD COLUMN cost_quantity DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER total,
    ADD COLUMN cost_amount DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER cost_quantity,
    ADD COLUMN costing_method VARCHAR(16) NULL AFTER cost_amount,
    ADD COLUMN cost_source VARCHAR(32) NULL AFTER costing_method,
    ADD COLUMN cost_account_id BIGINT UNSIGNED NULL AFTER cost_source,
    ADD COLUMN stock_account_id BIGINT UNSIGNED NULL AFTER cost_account_id,
    ADD KEY idx_customer_invoice_line_cost_account (cost_account_id),
    ADD KEY idx_customer_invoice_line_stock_account (stock_account_id),
    ADD CONSTRAINT fk_customer_invoice_line_cost_account FOREIGN KEY (cost_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_customer_invoice_line_stock_account FOREIGN KEY (stock_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_customer_invoice_line_cost CHECK (cost_quantity >= 0 AND cost_amount >= 0 AND (costing_method IS NULL OR costing_method IN ('standard','fifo','average')));
