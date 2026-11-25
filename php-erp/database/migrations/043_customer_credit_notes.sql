ALTER TABLE customer_invoices
    ADD COLUMN document_type VARCHAR(20) NOT NULL DEFAULT 'invoice' AFTER name,
    ADD COLUMN reversal_of_invoice_id BIGINT UNSIGNED NULL AFTER sales_order_id,
    ADD COLUMN reversal_reason VARCHAR(190) NULL AFTER reversal_of_invoice_id,
    ADD KEY idx_customer_invoice_reversal (company_id, reversal_of_invoice_id, state, id),
    ADD CONSTRAINT fk_customer_invoice_reversal FOREIGN KEY (reversal_of_invoice_id) REFERENCES customer_invoices (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_customer_invoice_document_type CHECK (document_type IN ('invoice','credit_note'));

ALTER TABLE customer_invoice_lines
    ADD COLUMN revenue_account_id_snapshot BIGINT UNSIGNED NULL AFTER tax_included_snapshot,
    ADD COLUMN tax_account_id_snapshot BIGINT UNSIGNED NULL AFTER revenue_account_id_snapshot,
    ADD COLUMN reversal_of_line_id BIGINT UNSIGNED NULL AFTER tax_account_id_snapshot,
    ADD KEY idx_customer_invoice_line_reversal (reversal_of_line_id, invoice_id),
    ADD KEY idx_customer_invoice_line_revenue_snapshot (revenue_account_id_snapshot),
    ADD KEY idx_customer_invoice_line_tax_snapshot (tax_account_id_snapshot),
    ADD CONSTRAINT fk_customer_invoice_line_reversal FOREIGN KEY (reversal_of_line_id) REFERENCES customer_invoice_lines (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_customer_invoice_line_revenue_account FOREIGN KEY (revenue_account_id_snapshot) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_customer_invoice_line_tax_account FOREIGN KEY (tax_account_id_snapshot) REFERENCES accounting_accounts (id) ON DELETE RESTRICT;

ALTER TABLE customer_invoices
    DROP CHECK chk_customer_invoice_payment_state,
    ADD CONSTRAINT chk_customer_invoice_payment_state CHECK (payment_state IN ('not_applicable','not_paid','partial','in_payment','paid','credit_open'));

CREATE TABLE IF NOT EXISTS customer_credit_note_sequences (
    company_id BIGINT UNSIGNED NOT NULL,
    sequence_year SMALLINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, sequence_year),
    CONSTRAINT fk_customer_credit_note_sequence_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
