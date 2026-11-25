ALTER TABLE stock_moves
    ADD COLUMN backorder_of_move_id BIGINT UNSIGNED NULL AFTER source_line_id,
    ADD KEY idx_stock_move_backorder (backorder_of_move_id),
    ADD CONSTRAINT fk_stock_move_backorder_parent FOREIGN KEY (backorder_of_move_id) REFERENCES stock_moves (id) ON DELETE RESTRICT;

ALTER TABLE stock_move_lines
    ADD COLUMN quantity_done DECIMAL(20,6) NULL AFTER quantity;

UPDATE stock_move_lines l
JOIN stock_moves m ON m.id=l.move_id
SET l.quantity_done=l.quantity
WHERE m.state='done';
