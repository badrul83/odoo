ALTER TABLE contacts
    ADD COLUMN street VARCHAR(190) NULL AFTER mobile,
    ADD COLUMN street2 VARCHAR(190) NULL AFTER street,
    ADD COLUMN city VARCHAR(120) NULL AFTER street2,
    ADD COLUMN state VARCHAR(120) NULL AFTER city,
    ADD COLUMN postal_code VARCHAR(40) NULL AFTER state,
    ADD COLUMN country_code CHAR(2) NULL AFTER postal_code,
    ADD KEY idx_contacts_country_city (country_code, city);

CREATE TABLE IF NOT EXISTS commission_plan_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commission_plan_id BIGINT UNSIGNED NOT NULL,
    product_category_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    sale_order_template_id BIGINT UNSIGNED NULL,
    pricelist_id BIGINT UNSIGNED NULL,
    rate DECIMAL(12, 4) NOT NULL DEFAULT 0,
    is_capped TINYINT(1) NOT NULL DEFAULT 0,
    max_commission DECIMAL(20, 4) NULL,
    sequence SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_commission_rule_plan_sequence (commission_plan_id, sequence, id),
    KEY idx_commission_rule_category (product_category_id),
    KEY idx_commission_rule_product (product_id),
    KEY idx_commission_rule_template (sale_order_template_id),
    CONSTRAINT fk_commission_rule_plan FOREIGN KEY (commission_plan_id) REFERENCES commission_plans (id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_rule_pricelist FOREIGN KEY (pricelist_id) REFERENCES price_lists (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
