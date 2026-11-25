ALTER TABLE stock_locations
    ADD COLUMN inventory_frequency_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER location_usage,
    ADD COLUMN last_inventory_date DATE NULL AFTER inventory_frequency_days,
    ADD COLUMN next_inventory_date DATE NULL AFTER last_inventory_date;

ALTER TABLE stock_quants
    ADD KEY idx_stock_quant_company_location_product (company_id, location_id, product_id, lot_key);

CREATE TABLE IF NOT EXISTS inventory_cycle_counts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(40) NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    scheduled_at DATE NULL,
    show_expected_quantity TINYINT(1) NOT NULL DEFAULT 1,
    state VARCHAR(16) NOT NULL DEFAULT 'counting',
    note TEXT NULL,
    accounting_move_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    validated_by BIGINT UNSIGNED NULL,
    validated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cycle_count_company_reference (company_id, reference),
    UNIQUE KEY uq_cycle_count_accounting_move (accounting_move_id),
    KEY idx_cycle_count_company_state (company_id, state, created_at, id),
    CONSTRAINT fk_cycle_count_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_location FOREIGN KEY (location_id) REFERENCES stock_locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_assignee FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_accounting_move FOREIGN KEY (accounting_move_id) REFERENCES accounting_moves (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_validator FOREIGN KEY (validated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_cycle_count_state CHECK (state IN ('counting','done','cancelled')),
    CONSTRAINT chk_cycle_count_show_expected CHECK (show_expected_quantity IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_cycle_count_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_count_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NULL,
    lot_code_snapshot VARCHAR(120) NULL,
    expiration_date_snapshot DATE NULL,
    dimension_key VARCHAR(140) NOT NULL,
    theoretical_quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
    reserved_quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
    counted_quantity DECIMAL(20,6) NULL,
    difference_quantity DECIMAL(20,6) NULL,
    adjustment_move_id BIGINT UNSIGNED NULL,
    adjustment_move_line_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cycle_count_line_dimension (cycle_count_id, product_id, dimension_key),
    UNIQUE KEY uq_cycle_count_line_adjustment_move_line (adjustment_move_line_id),
    KEY idx_cycle_count_line_product (cycle_count_id, product_id, id),
    KEY idx_cycle_count_line_lot (lot_id),
    CONSTRAINT fk_cycle_count_line_count FOREIGN KEY (cycle_count_id) REFERENCES inventory_cycle_counts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_line_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_line_lot FOREIGN KEY (lot_id) REFERENCES stock_lots (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_line_adjustment_move FOREIGN KEY (adjustment_move_id) REFERENCES stock_moves (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_line_adjustment_move_line FOREIGN KEY (adjustment_move_line_id) REFERENCES stock_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cycle_count_line_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_cycle_count_line_quantities CHECK (reserved_quantity >= 0 AND (counted_quantity IS NULL OR counted_quantity >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
