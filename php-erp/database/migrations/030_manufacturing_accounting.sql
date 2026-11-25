ALTER TABLE manufacturing_orders
    ADD COLUMN accounting_move_id BIGINT UNSIGNED NULL AFTER cost_variance,
    ADD UNIQUE KEY uq_mrp_order_accounting_move (accounting_move_id),
    ADD CONSTRAINT fk_mrp_order_accounting_move FOREIGN KEY (accounting_move_id) REFERENCES accounting_moves (id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS manufacturing_accounting_sequences (
    company_id BIGINT UNSIGNED NOT NULL,
    sequence_year SMALLINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, sequence_year),
    CONSTRAINT fk_mrp_accounting_sequence_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
