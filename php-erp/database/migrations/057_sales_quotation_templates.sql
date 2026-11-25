ALTER TABLE sales_order_templates
    ADD COLUMN validity_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN pricelist_id BIGINT UNSIGNED NULL AFTER validity_days,
    ADD COLUMN terms TEXT NULL AFTER pricelist_id,
    ADD CONSTRAINT fk_sales_order_template_pricelist FOREIGN KEY (pricelist_id) REFERENCES price_lists (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_sales_order_template_validity CHECK (validity_days <= 3650);

CREATE TABLE IF NOT EXISTS sales_order_template_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    product_id BIGINT UNSIGNED NOT NULL,
    uom_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(20,6) NOT NULL DEFAULT 1,
    unit_price DECIMAL(20,6) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0,
    tax_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_order_template_line (template_id, sequence, id),
    CONSTRAINT fk_sales_order_template_line_template FOREIGN KEY (template_id) REFERENCES sales_order_templates (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_order_template_line_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_order_template_line_uom FOREIGN KEY (uom_id) REFERENCES units_of_measure (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_order_template_line_tax FOREIGN KEY (tax_id) REFERENCES sales_taxes (id) ON DELETE RESTRICT,
    CONSTRAINT chk_sales_order_template_line_quantity CHECK (quantity > 0),
    CONSTRAINT chk_sales_order_template_line_amount CHECK (unit_price >= 0 AND discount_percent >= 0 AND discount_percent <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales_orders
    ADD COLUMN sale_order_template_id BIGINT UNSIGNED NULL AFTER crm_lead_id,
    ADD CONSTRAINT fk_sales_order_template FOREIGN KEY (sale_order_template_id) REFERENCES sales_order_templates (id) ON DELETE RESTRICT,
    ADD KEY idx_sales_order_template (company_id, sale_order_template_id);
