ALTER TABLE purchase_orders
    ADD COLUMN arrival_confirmation TINYINT(1) NOT NULL DEFAULT 0 AFTER expected_arrival;
