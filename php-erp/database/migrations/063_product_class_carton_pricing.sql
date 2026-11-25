ALTER TABLE products
    ADD COLUMN product_class VARCHAR(16) NOT NULL DEFAULT 'unclassified' AFTER product_type,
    ADD COLUMN carton_size VARCHAR(64) NULL AFTER product_class,
    ADD COLUMN units_per_carton INT UNSIGNED NULL AFTER carton_size,
    ADD COLUMN carton_price DECIMAL(20, 6) NULL AFTER units_per_carton;
