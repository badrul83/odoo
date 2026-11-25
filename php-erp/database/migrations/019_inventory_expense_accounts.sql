ALTER TABLE inventory_accounting_settings
    ADD COLUMN expense_adjustment_account_id BIGINT UNSIGNED NULL AFTER variation_account_id,
    ADD KEY idx_inventory_accounting_expense_adjustment_account (expense_adjustment_account_id),
    ADD CONSTRAINT fk_inventory_accounting_expense_adjustment_account FOREIGN KEY (expense_adjustment_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT;

ALTER TABLE product_category_accounting_overrides
    ADD COLUMN expense_account_id BIGINT UNSIGNED NULL AFTER variation_account_id,
    ADD COLUMN expense_adjustment_account_id BIGINT UNSIGNED NULL AFTER expense_account_id,
    ADD COLUMN price_difference_account_id BIGINT UNSIGNED NULL AFTER expense_adjustment_account_id,
    ADD KEY idx_category_override_expense_account (expense_account_id),
    ADD KEY idx_category_override_expense_adjustment_account (expense_adjustment_account_id),
    ADD KEY idx_category_override_price_difference_account (price_difference_account_id),
    ADD CONSTRAINT fk_category_override_expense_account FOREIGN KEY (expense_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_category_override_expense_adjustment_account FOREIGN KEY (expense_adjustment_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_category_override_price_difference_account FOREIGN KEY (price_difference_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT;
