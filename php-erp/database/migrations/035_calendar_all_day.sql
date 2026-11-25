ALTER TABLE calendar_events
    ADD COLUMN all_day TINYINT(1) NOT NULL DEFAULT 0 AFTER ends_at_utc,
    ADD KEY idx_calendar_company_all_day_start (company_id, all_day, starts_at_utc, ends_at_utc, status);
