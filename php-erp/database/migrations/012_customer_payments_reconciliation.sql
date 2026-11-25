ALTER TABLE accounting_journals
    ADD COLUMN default_account_id BIGINT UNSIGNED NULL AFTER journal_type,
    ADD COLUMN outstanding_receipts_account_id BIGINT UNSIGNED NULL AFTER default_account_id,
    ADD KEY idx_accounting_journal_default_account (default_account_id),
    ADD KEY idx_accounting_journal_outstanding_account (outstanding_receipts_account_id),
    ADD CONSTRAINT fk_accounting_journal_default_account FOREIGN KEY (default_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_accounting_journal_outstanding_account FOREIGN KEY (outstanding_receipts_account_id) REFERENCES accounting_accounts (id) ON DELETE RESTRICT;

ALTER TABLE customer_invoices
    ADD COLUMN payment_state VARCHAR(20) NOT NULL DEFAULT 'not_applicable' AFTER state,
    ADD COLUMN amount_paid DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER total_amount,
    ADD COLUMN amount_residual DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER amount_paid,
    ADD CONSTRAINT chk_customer_invoice_payment_state CHECK (payment_state IN ('not_applicable','not_paid','partial','in_payment','paid')),
    ADD CONSTRAINT chk_customer_invoice_payment_amounts CHECK (amount_paid >= 0 AND amount_residual >= 0);

UPDATE customer_invoices
SET payment_state = CASE WHEN state='posted' THEN 'not_paid' ELSE 'not_applicable' END,
    amount_residual = CASE WHEN state='posted' THEN total_amount ELSE 0 END;

CREATE TABLE IF NOT EXISTS customer_payment_sequences (
    company_id BIGINT UNSIGNED NOT NULL,
    sequence_year SMALLINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, sequence_year),
    CONSTRAINT fk_customer_payment_sequence_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    partner_contact_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    currency_code CHAR(3) NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(20,6) NOT NULL,
    reference VARCHAR(190) NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'in_payment',
    accounting_move_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_payment_company_name (company_id, name),
    UNIQUE KEY uq_customer_payment_move (accounting_move_id),
    KEY idx_customer_payment_invoice (company_id, invoice_id, state, id),
    CONSTRAINT fk_customer_payment_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_invoice FOREIGN KEY (invoice_id) REFERENCES customer_invoices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_partner FOREIGN KEY (partner_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_journal FOREIGN KEY (journal_id) REFERENCES accounting_journals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_move FOREIGN KEY (accounting_move_id) REFERENCES accounting_moves (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_payment_state CHECK (state IN ('in_payment','paid','cancelled')),
    CONSTRAINT chk_customer_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_receivable_line_id BIGINT UNSIGNED NOT NULL,
    payment_receivable_line_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(20,6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_payment_allocation_payment (payment_id),
    KEY idx_invoice_payment_allocation_invoice (company_id, invoice_id, id),
    CONSTRAINT fk_invoice_payment_allocation_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_payment_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES customer_invoices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_payment_allocation_payment FOREIGN KEY (payment_id) REFERENCES customer_payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_payment_allocation_invoice_line FOREIGN KEY (invoice_receivable_line_id) REFERENCES accounting_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_payment_allocation_payment_line FOREIGN KEY (payment_receivable_line_id) REFERENCES accounting_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_payment_allocation_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_invoice_payment_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_statement_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(190) NOT NULL,
    transaction_date DATE NOT NULL,
    amount DECIMAL(20,6) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    accounting_move_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    matched_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_statement_match_payment (payment_id),
    UNIQUE KEY uq_bank_statement_match_move (accounting_move_id),
    KEY idx_bank_statement_match_company_date (company_id, journal_id, transaction_date, id),
    CONSTRAINT fk_bank_statement_match_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_match_journal FOREIGN KEY (journal_id) REFERENCES accounting_journals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_match_payment FOREIGN KEY (payment_id) REFERENCES customer_payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_match_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_match_move FOREIGN KEY (accounting_move_id) REFERENCES accounting_moves (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_match_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_bank_statement_match_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
