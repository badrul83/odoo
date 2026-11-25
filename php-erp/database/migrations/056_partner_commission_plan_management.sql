INSERT INTO access_permissions (permission_key, module_key, description)
VALUES ('partner_commissions.manage', 'partner_commissions', 'Create, edit, and archive partner commission plans')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS sales_order_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_order_template_company_name (company_id, name),
    KEY idx_sales_order_template_company_active (company_id, active, name),
    CONSTRAINT fk_sales_order_template_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE commission_plans
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_commission_plan_purchase_product FOREIGN KEY (purchase_default_product_id) REFERENCES products (id) ON DELETE RESTRICT;

ALTER TABLE commission_plan_rules
    ADD CONSTRAINT fk_commission_rule_category FOREIGN KEY (product_category_id) REFERENCES product_categories (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_commission_rule_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_commission_rule_sales_template FOREIGN KEY (sale_order_template_id) REFERENCES sales_order_templates (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_commission_rule_rate CHECK (rate >= 0),
    ADD CONSTRAINT chk_commission_rule_cap CHECK ((is_capped = 0 AND max_commission IS NULL) OR (is_capped = 1 AND max_commission > 0));
