CREATE TABLE IF NOT EXISTS bank_statements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    source_file_name VARCHAR(190) NOT NULL,
    source_file_hash CHAR(64) NOT NULL,
    statement_date_from DATE NOT NULL,
    statement_date_to DATE NOT NULL,
    opening_balance DECIMAL(20,6) NOT NULL,
    closing_balance DECIMAL(20,6) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    line_count INT UNSIGNED NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'imported',
    created_by BIGINT UNSIGNED NOT NULL,
    reconciled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_statement_company_name (company_id, name),
    UNIQUE KEY uq_bank_statement_source (company_id, journal_id, source_file_hash),
    KEY idx_bank_statement_company_journal_period (company_id, journal_id, statement_date_to, id),
    CONSTRAINT fk_bank_statement_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_journal FOREIGN KEY (journal_id) REFERENCES accounting_journals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_bank_statement_dates CHECK (statement_date_to >= statement_date_from),
    CONSTRAINT chk_bank_statement_line_count CHECK (line_count > 0),
    CONSTRAINT chk_bank_statement_state CHECK (state IN ('imported','reconciled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_statement_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    statement_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    reference VARCHAR(190) NOT NULL,
    description VARCHAR(500) NULL,
    amount DECIMAL(20,6) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    partner_email VARCHAR(190) NULL,
    external_id VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_statement_line_number (statement_id, line_number),
    UNIQUE KEY uq_bank_statement_line_external_id (statement_id, external_id),
    KEY idx_bank_statement_line_date (statement_id, transaction_date, id),
    CONSTRAINT fk_bank_statement_line_statement FOREIGN KEY (statement_id) REFERENCES bank_statements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bank_statement_line_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT,
    CONSTRAINT chk_bank_statement_line_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bank_statement_matches
    ADD COLUMN statement_line_id BIGINT UNSIGNED NULL AFTER payment_id,
    ADD UNIQUE KEY uq_bank_statement_match_imported_line (statement_line_id),
    ADD CONSTRAINT fk_bank_statement_match_imported_line FOREIGN KEY (statement_line_id) REFERENCES bank_statement_lines (id) ON DELETE RESTRICT;

ALTER TABLE vendor_bank_statement_matches
    ADD COLUMN statement_line_id BIGINT UNSIGNED NULL AFTER payment_id,
    ADD UNIQUE KEY uq_vendor_bank_match_imported_line (statement_line_id),
    ADD CONSTRAINT fk_vendor_bank_match_imported_line FOREIGN KEY (statement_line_id) REFERENCES bank_statement_lines (id) ON DELETE RESTRICT;
