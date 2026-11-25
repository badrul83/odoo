CREATE TABLE IF NOT EXISTS customer_invoice_stock_cost_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    invoice_line_id BIGINT UNSIGNED NOT NULL,
    valuation_layer_id BIGINT UNSIGNED NOT NULL,
    quantity_product_uom DECIMAL(20,6) NOT NULL,
    cost_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_stock_cost_line_layer (invoice_line_id, valuation_layer_id),
    KEY idx_invoice_stock_cost_company_layer (company_id, valuation_layer_id, id),
    CONSTRAINT fk_invoice_stock_cost_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_stock_cost_line FOREIGN KEY (invoice_line_id) REFERENCES customer_invoice_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_stock_cost_layer FOREIGN KEY (valuation_layer_id) REFERENCES inventory_valuation_layers (id) ON DELETE RESTRICT,
    CONSTRAINT chk_invoice_stock_cost_allocation CHECK (quantity_product_uom > 0 AND cost_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_credit_note_return_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    credit_note_line_id BIGINT UNSIGNED NOT NULL,
    invoice_cost_allocation_id BIGINT UNSIGNED NULL,
    stock_move_line_id BIGINT UNSIGNED NOT NULL,
    quantity_product_uom DECIMAL(20,6) NOT NULL,
    cost_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit_note_return_line (credit_note_line_id, stock_move_line_id),
    KEY idx_credit_note_return_stock_line (stock_move_line_id, id),
    KEY idx_credit_note_return_cost_allocation (invoice_cost_allocation_id, id),
    CONSTRAINT fk_credit_note_return_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_note_return_credit_line FOREIGN KEY (credit_note_line_id) REFERENCES customer_invoice_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_note_return_cost_allocation FOREIGN KEY (invoice_cost_allocation_id) REFERENCES customer_invoice_stock_cost_allocations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_note_return_stock_line FOREIGN KEY (stock_move_line_id) REFERENCES stock_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_note_return_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_credit_note_return_allocation CHECK (quantity_product_uom > 0 AND cost_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
