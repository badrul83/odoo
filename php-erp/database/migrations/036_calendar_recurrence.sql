ALTER TABLE calendar_events
    ADD COLUMN recurrence_frequency VARCHAR(12) NULL AFTER all_day,
    ADD COLUMN recurrence_interval TINYINT UNSIGNED NULL AFTER recurrence_frequency,
    ADD COLUMN recurrence_weekdays JSON NULL AFTER recurrence_interval,
    ADD COLUMN recurrence_month_by VARCHAR(12) NULL AFTER recurrence_weekdays,
    ADD COLUMN recurrence_day TINYINT UNSIGNED NULL AFTER recurrence_month_by,
    ADD COLUMN recurrence_weekday TINYINT UNSIGNED NULL AFTER recurrence_day,
    ADD COLUMN recurrence_week_ordinal TINYINT NULL AFTER recurrence_weekday,
    ADD COLUMN recurrence_end_type VARCHAR(12) NULL AFTER recurrence_week_ordinal,
    ADD COLUMN recurrence_count SMALLINT UNSIGNED NULL AFTER recurrence_end_type,
    ADD COLUMN recurrence_until_date DATE NULL AFTER recurrence_count,
    ADD KEY idx_calendar_recurrence_company_end (company_id, recurrence_frequency, recurrence_until_date, status);

ALTER TABLE calendar_events
    ADD CONSTRAINT chk_calendar_recurrence_frequency CHECK (recurrence_frequency IS NULL OR recurrence_frequency IN ('daily','weekly','monthly','yearly')),
    ADD CONSTRAINT chk_calendar_recurrence_interval CHECK (recurrence_interval IS NULL OR recurrence_interval BETWEEN 1 AND 99),
    ADD CONSTRAINT chk_calendar_recurrence_month_by CHECK (recurrence_month_by IS NULL OR recurrence_month_by IN ('day','weekday')),
    ADD CONSTRAINT chk_calendar_recurrence_week_ordinal CHECK (recurrence_week_ordinal IS NULL OR recurrence_week_ordinal IN (-1,1,2,3,4)),
    ADD CONSTRAINT chk_calendar_recurrence_end_type CHECK (recurrence_end_type IS NULL OR recurrence_end_type IN ('count','date','forever')),
    ADD CONSTRAINT chk_calendar_recurrence_config CHECK (
        (recurrence_frequency IS NULL AND recurrence_interval IS NULL AND recurrence_weekdays IS NULL AND recurrence_month_by IS NULL AND recurrence_day IS NULL AND recurrence_weekday IS NULL AND recurrence_week_ordinal IS NULL AND recurrence_end_type IS NULL AND recurrence_count IS NULL AND recurrence_until_date IS NULL)
        OR
        (recurrence_frequency IS NOT NULL AND recurrence_interval BETWEEN 1 AND 99 AND recurrence_end_type IS NOT NULL AND recurrence_until_date IS NOT NULL AND
            ((recurrence_frequency = 'weekly' AND JSON_TYPE(recurrence_weekdays) = 'ARRAY' AND JSON_LENGTH(recurrence_weekdays) BETWEEN 1 AND 7) OR (recurrence_frequency <> 'weekly' AND recurrence_weekdays IS NULL)) AND
            ((recurrence_frequency = 'monthly' AND recurrence_month_by = 'day' AND recurrence_day BETWEEN 1 AND 31 AND recurrence_weekday IS NULL AND recurrence_week_ordinal IS NULL) OR
             (recurrence_frequency = 'monthly' AND recurrence_month_by = 'weekday' AND recurrence_weekday BETWEEN 1 AND 7 AND recurrence_week_ordinal IN (-1,1,2,3,4) AND recurrence_day IS NULL) OR
             (recurrence_frequency <> 'monthly' AND recurrence_month_by IS NULL AND recurrence_day IS NULL AND recurrence_weekday IS NULL AND recurrence_week_ordinal IS NULL)) AND
            ((recurrence_end_type = 'count' AND recurrence_count BETWEEN 1 AND 5480) OR (recurrence_end_type <> 'count' AND recurrence_count IS NULL)))
    );
