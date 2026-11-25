CREATE TABLE IF NOT EXISTS uom_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uom_category_company_name (company_id, name),
    CONSTRAINT fk_uom_category_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units_of_measure (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    abbreviation VARCHAR(24) NOT NULL,
    unit_type VARCHAR(16) NOT NULL DEFAULT 'reference',
    factor_to_reference DECIMAL(20, 10) NOT NULL DEFAULT 1,
    rounding DECIMAL(20, 10) NOT NULL DEFAULT 0.01,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uom_company_category_name (company_id, category_id, name),
    KEY idx_uom_category_active (category_id, active, name),
    CONSTRAINT fk_uom_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_uom_category FOREIGN KEY (category_id) REFERENCES uom_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    parent_category_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    costing_method VARCHAR(16) NOT NULL DEFAULT 'fifo',
    inventory_valuation VARCHAR(16) NOT NULL DEFAULT 'manual',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_category_company_name (company_id, name),
    KEY idx_product_category_parent (parent_category_id),
    CONSTRAINT fk_product_category_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_category_parent FOREIGN KEY (parent_category_id) REFERENCES product_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    partner_contact_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(12) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouse_company_code (company_id, code),
    KEY idx_warehouse_company_name (company_id, active, name),
    CONSTRAINT fk_warehouse_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_warehouse_partner FOREIGN KEY (partner_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NULL,
    parent_location_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    complete_name VARCHAR(500) NOT NULL,
    location_usage VARCHAR(16) NOT NULL DEFAULT 'internal',
    barcode VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_location_company_barcode (company_id, barcode),
    KEY idx_stock_location_warehouse (warehouse_id, active, location_usage),
    KEY idx_stock_location_parent (parent_location_id),
    CONSTRAINT fk_stock_location_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_location_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_location_parent FOREIGN KEY (parent_location_id) REFERENCES stock_locations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    uom_id BIGINT UNSIGNED NOT NULL,
    purchase_uom_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    internal_reference VARCHAR(100) NULL,
    barcode VARCHAR(100) NULL,
    product_type VARCHAR(16) NOT NULL DEFAULT 'goods',
    tracking VARCHAR(16) NOT NULL DEFAULT 'none',
    sale_ok TINYINT(1) NOT NULL DEFAULT 1,
    purchase_ok TINYINT(1) NOT NULL DEFAULT 1,
    list_price DECIMAL(20, 6) NOT NULL DEFAULT 0,
    cost_price DECIMAL(20, 6) NOT NULL DEFAULT 0,
    weight DECIMAL(20, 6) NOT NULL DEFAULT 0,
    volume DECIMAL(20, 6) NOT NULL DEFAULT 0,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_company_reference (company_id, internal_reference),
    UNIQUE KEY uq_product_company_barcode (company_id, barcode),
    KEY idx_products_company_name (company_id, active, name),
    KEY idx_products_category (category_id, active),
    KEY idx_products_barcode (barcode),
    CONSTRAINT fk_product_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES product_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_uom FOREIGN KEY (uom_id) REFERENCES units_of_measure (id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_purchase_uom FOREIGN KEY (purchase_uom_id) REFERENCES units_of_measure (id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO uom_categories (company_id, name)
SELECT NULL, seed.name FROM (
    SELECT 'Units' AS name UNION ALL SELECT 'Weight' UNION ALL SELECT 'Volume'
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM uom_categories existing WHERE existing.company_id IS NULL AND existing.name = seed.name
);

INSERT INTO units_of_measure (company_id, category_id, name, abbreviation, unit_type, factor_to_reference, rounding)
SELECT NULL, c.id, seed.name, seed.abbreviation, seed.unit_type, seed.factor_to_reference, seed.rounding
FROM uom_categories c
JOIN (
    SELECT 'Units' AS category_name, 'Unit' AS name, 'Unit' AS abbreviation, 'reference' AS unit_type, 1.0000000000 AS factor_to_reference, 1.0000000000 AS rounding
    UNION ALL SELECT 'Units', 'Dozen', 'doz', 'bigger', 12.0000000000, 1.0000000000
    UNION ALL SELECT 'Weight', 'Kilogram', 'kg', 'reference', 1.0000000000, 0.0010000000
    UNION ALL SELECT 'Weight', 'Gram', 'g', 'smaller', 0.0010000000, 1.0000000000
    UNION ALL SELECT 'Weight', 'Metric Ton', 't', 'bigger', 1000.0000000000, 0.0000010000
    UNION ALL SELECT 'Volume', 'Liter', 'L', 'reference', 1.0000000000, 0.0010000000
    UNION ALL SELECT 'Volume', 'Milliliter', 'mL', 'smaller', 0.0010000000, 1.0000000000
) seed ON seed.category_name = c.name
WHERE c.company_id IS NULL AND NOT EXISTS (
    SELECT 1 FROM units_of_measure existing WHERE existing.company_id IS NULL AND existing.category_id = c.id AND existing.name = seed.name
);

INSERT INTO product_categories (company_id, name, costing_method, inventory_valuation)
SELECT NULL, 'All', 'fifo', 'manual' WHERE NOT EXISTS (
    SELECT 1 FROM product_categories existing WHERE existing.company_id IS NULL AND existing.name = 'All'
);
