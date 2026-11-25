ALTER TABLE users
    ADD COLUMN calendar_default_view VARCHAR(8) NOT NULL DEFAULT 'month' AFTER expertise,
    ADD COLUMN calendar_show_weekends TINYINT(1) NOT NULL DEFAULT 1 AFTER calendar_default_view;
