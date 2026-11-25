ALTER TABLE users
    ADD COLUMN default_warehouse_id BIGINT UNSIGNED NULL AFTER calendar_show_weekends,
    ADD CONSTRAINT fk_user_default_warehouse
        FOREIGN KEY (default_warehouse_id) REFERENCES warehouses (id) ON DELETE SET NULL;
