CREATE TABLE IF NOT EXISTS accounting_manual_entry_sequences (
    company_id BIGINT UNSIGNED NOT NULL,
    sequence_year SMALLINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, sequence_year),
    CONSTRAINT fk_manual_entry_sequence_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_manual_journal_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    accounting_move_id BIGINT UNSIGNED NULL,
    reversal_of_entry_id BIGINT UNSIGNED NULL,
    reference VARCHAR(120) NOT NULL DEFAULT '',
    narration TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_manual_entry_company_name (company_id, name),
    UNIQUE KEY uq_manual_entry_move (accounting_move_id),
    UNIQUE KEY uq_manual_entry_reversal (reversal_of_entry_id),
    KEY idx_manual_entry_company_created (company_id, created_at, id),
    CONSTRAINT fk_manual_entry_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_manual_entry_move FOREIGN KEY (accounting_move_id) REFERENCES accounting_moves (id) ON DELETE RESTRICT,
    CONSTRAINT fk_manual_entry_reversal FOREIGN KEY (reversal_of_entry_id) REFERENCES accounting_manual_journal_entries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_manual_entry_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_manual_entry_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
