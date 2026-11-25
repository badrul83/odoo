ALTER TABLE vendor_credit_notes
    ADD COLUMN amount_applied DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER amount_refunded;

ALTER TABLE vendor_bills
    ADD COLUMN amount_credited DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER amount_paid;

ALTER TABLE vendor_credit_notes
    ADD UNIQUE KEY uq_vendor_credit_note_company_id_id (company_id, id);

ALTER TABLE vendor_bills
    ADD UNIQUE KEY uq_vendor_bill_company_id_id (company_id, id);

CREATE TABLE IF NOT EXISTS vendor_credit_note_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    credit_note_id BIGINT UNSIGNED NOT NULL,
    vendor_bill_id BIGINT UNSIGNED NOT NULL,
    credit_note_payable_line_id BIGINT UNSIGNED NOT NULL,
    vendor_bill_payable_line_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(20,6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    applied_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vendor_credit_application_note (company_id, credit_note_id, id),
    KEY idx_vendor_credit_application_bill (company_id, vendor_bill_id, id),
    CONSTRAINT fk_vendor_credit_application_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_vendor_credit_application_note FOREIGN KEY (company_id, credit_note_id) REFERENCES vendor_credit_notes (company_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_vendor_credit_application_bill FOREIGN KEY (company_id, vendor_bill_id) REFERENCES vendor_bills (company_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_vendor_credit_application_note_line FOREIGN KEY (credit_note_payable_line_id) REFERENCES accounting_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_vendor_credit_application_bill_line FOREIGN KEY (vendor_bill_payable_line_id) REFERENCES accounting_move_lines (id) ON DELETE RESTRICT,
    CONSTRAINT fk_vendor_credit_application_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_vendor_credit_application_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
