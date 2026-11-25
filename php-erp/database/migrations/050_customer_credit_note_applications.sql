ALTER TABLE customer_invoices
    ADD COLUMN amount_applied DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER amount_paid,
    ADD COLUMN amount_credited DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER amount_applied,
    ADD UNIQUE KEY uq_customer_invoice_company_id_id (company_id, id);

CREATE TABLE IF NOT EXISTS customer_credit_note_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    credit_note_id BIGINT UNSIGNED NOT NULL,
    customer_invoice_id BIGINT UNSIGNED NOT NULL,
    credit_note_receivable_line_id BIGINT UNSIGNED NOT NULL,
    customer_invoice_receivable_line_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(20,6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    applied_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_credit_application_note (company_id, credit_note_id, id),
    KEY idx_customer_credit_application_invoice (company_id, customer_invoice_id, id),
    CONSTRAINT fk_customer_credit_application_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_credit_application_note FOREIGN KEY (company_id, credit_note_id) REFERENCES customer_invoices (company_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_credit_application_invoice FOREIGN KEY (company_id, customer_invoice_id) REFERENCES customer_invoices (company_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_credit_application_note_line FOREIGN KEY (credit_note_receivable_line_id) REFERENCES accounting_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_credit_application_invoice_line FOREIGN KEY (customer_invoice_receivable_line_id) REFERENCES accounting_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_credit_application_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_credit_application_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
