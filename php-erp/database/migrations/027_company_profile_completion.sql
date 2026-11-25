ALTER TABLE company_branches
    ADD COLUMN legal_name VARCHAR(190) NULL AFTER name,
    ADD COLUMN email_domain VARCHAR(190) NULL AFTER email,
    ADD COLUMN external_code VARCHAR(100) NULL AFTER code,
    ADD COLUMN color SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER external_code,
    ADD COLUMN logo_path VARCHAR(500) NULL AFTER color,
    ADD COLUMN x_account_url VARCHAR(255) NULL AFTER timezone,
    ADD COLUMN facebook_account_url VARCHAR(255) NULL AFTER x_account_url,
    ADD COLUMN github_account_url VARCHAR(255) NULL AFTER facebook_account_url,
    ADD COLUMN linkedin_account_url VARCHAR(255) NULL AFTER github_account_url,
    ADD COLUMN youtube_account_url VARCHAR(255) NULL AFTER linkedin_account_url;
