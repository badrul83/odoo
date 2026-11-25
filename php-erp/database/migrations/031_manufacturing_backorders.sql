ALTER TABLE manufacturing_orders
    ADD COLUMN backorder_root_id BIGINT UNSIGNED NULL AFTER accounting_move_id,
    ADD COLUMN backorder_sequence INT UNSIGNED NULL AFTER backorder_root_id,
    ADD UNIQUE KEY uq_mrp_order_backorder_sequence (company_id, backorder_root_id, backorder_sequence),
    ADD CONSTRAINT fk_mrp_order_backorder_root FOREIGN KEY (backorder_root_id)
        REFERENCES manufacturing_orders (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_mrp_order_backorder_fields CHECK (
        (backorder_root_id IS NULL AND backorder_sequence IS NULL)
        OR (backorder_root_id IS NOT NULL AND backorder_sequence IS NOT NULL)
    );
