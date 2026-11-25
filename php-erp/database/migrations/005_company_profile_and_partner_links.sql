ALTER TABLE companies
    ADD COLUMN contact_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN street VARCHAR(190) NULL AFTER tax_id,
    ADD COLUMN street2 VARCHAR(190) NULL AFTER street,
    ADD COLUMN city VARCHAR(120) NULL AFTER street2,
    ADD COLUMN state VARCHAR(120) NULL AFTER city,
    ADD COLUMN postal_code VARCHAR(40) NULL AFTER state,
    ADD COLUMN country_code CHAR(2) NULL AFTER postal_code,
    ADD COLUMN email_domain VARCHAR(190) NULL AFTER website,
    ADD COLUMN color SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_domain,
    ADD COLUMN external_code VARCHAR(100) NULL AFTER color,
    ADD COLUMN x_account_url VARCHAR(255) NULL AFTER external_code,
    ADD COLUMN facebook_account_url VARCHAR(255) NULL AFTER x_account_url,
    ADD COLUMN github_account_url VARCHAR(255) NULL AFTER facebook_account_url,
    ADD COLUMN linkedin_account_url VARCHAR(255) NULL AFTER github_account_url,
    ADD COLUMN youtube_account_url VARCHAR(255) NULL AFTER linkedin_account_url,
    ADD UNIQUE KEY uq_companies_contact (contact_id),
    ADD CONSTRAINT fk_company_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_company_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT;

ALTER TABLE company_branches
    ADD COLUMN partner_contact_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN tax_id VARCHAR(80) NULL AFTER phone,
    ADD COLUMN website VARCHAR(255) NULL AFTER tax_id,
    ADD COLUMN currency_code CHAR(3) NULL AFTER country_code,
    ADD COLUMN timezone VARCHAR(80) NULL AFTER currency_code,
    ADD KEY idx_company_branch_partner (partner_contact_id),
    ADD CONSTRAINT fk_company_branch_partner FOREIGN KEY (partner_contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_company_branch_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code) ON DELETE RESTRICT;
