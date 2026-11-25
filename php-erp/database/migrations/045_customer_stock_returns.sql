ALTER TABLE stock_moves
    ADD COLUMN return_of_move_id BIGINT UNSIGNED NULL AFTER backorder_of_move_id,
    ADD COLUMN return_reason VARCHAR(190) NULL AFTER return_of_move_id,
    ADD KEY idx_stock_move_customer_return (return_of_move_id, state, id),
    ADD CONSTRAINT fk_stock_move_customer_return_parent FOREIGN KEY (return_of_move_id) REFERENCES stock_moves (id) ON DELETE RESTRICT;

ALTER TABLE stock_move_lines
    ADD COLUMN return_of_move_line_id BIGINT UNSIGNED NULL AFTER quantity_done,
    ADD KEY idx_stock_move_line_customer_return (return_of_move_line_id, move_id),
    ADD CONSTRAINT fk_stock_move_line_customer_return_parent FOREIGN KEY (return_of_move_line_id) REFERENCES stock_move_lines (id) ON DELETE RESTRICT;
