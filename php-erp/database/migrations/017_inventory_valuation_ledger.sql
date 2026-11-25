ALTER TABLE stock_moves
    ADD COLUMN valuation_value DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER done_quantity;

CREATE TABLE IF NOT EXISTS inventory_valuation_balances (
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
    value DECIMAL(20,6) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id, product_id),
    CONSTRAINT fk_inventory_valuation_balance_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_valuation_balance_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_valuation_balance_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_inventory_valuation_balance_value CHECK (value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_valuation_layers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    stock_move_id BIGINT UNSIGNED NULL,
    stock_move_line_id BIGINT UNSIGNED NULL,
    source_model VARCHAR(40) NULL,
    source_record_id BIGINT UNSIGNED NULL,
    reference VARCHAR(120) NOT NULL,
    valuation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    costing_method VARCHAR(16) NOT NULL,
    quantity_delta DECIMAL(20,6) NOT NULL,
    unit_cost DECIMAL(20,6) NOT NULL DEFAULT 0,
    value_delta DECIMAL(20,6) NOT NULL,
    remaining_quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
    remaining_value DECIMAL(20,6) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_valuation_move_line (stock_move_line_id),
    KEY idx_inventory_valuation_company_date (company_id, valuation_date, id),
    KEY idx_inventory_valuation_product_date (company_id, product_id, valuation_date, id),
    KEY idx_inventory_valuation_fifo (company_id, product_id, remaining_quantity, valuation_date, id),
    CONSTRAINT fk_inventory_valuation_layer_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_valuation_layer_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_valuation_layer_move FOREIGN KEY (stock_move_id) REFERENCES stock_moves (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_valuation_layer_move_line FOREIGN KEY (stock_move_line_id) REFERENCES stock_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_valuation_layer_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_valuation_cost_method CHECK (costing_method IN ('standard','fifo','average')),
    CONSTRAINT chk_inventory_valuation_layer_values CHECK (unit_cost >= 0 AND remaining_quantity >= 0 AND remaining_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO inventory_valuation_balances (company_id, product_id, quantity, value)
SELECT q.company_id, q.product_id, SUM(q.quantity), CAST(SUM(q.quantity * p.cost_price) AS DECIMAL(20,6))
FROM stock_quants q
JOIN products p ON p.id = q.product_id AND p.company_id = q.company_id
JOIN stock_locations l ON l.id = q.location_id AND l.location_usage IN ('internal','transit')
GROUP BY q.company_id, q.product_id
HAVING SUM(q.quantity) > 0;

INSERT INTO inventory_valuation_layers (company_id, product_id, reference, costing_method, quantity_delta, unit_cost, value_delta, remaining_quantity, remaining_value)
SELECT b.company_id, b.product_id, 'Opening valuation · migration 017', c.costing_method, b.quantity,
       CASE WHEN b.quantity = 0 THEN 0 ELSE CAST(b.value / b.quantity AS DECIMAL(20,6)) END,
       b.value, b.quantity, b.value
FROM inventory_valuation_balances b
JOIN products p ON p.id = b.product_id
JOIN product_categories c ON c.id = p.category_id;
