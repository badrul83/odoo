CREATE TABLE IF NOT EXISTS calendar_event_occurrence_exceptions (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    occurrence_start_utc DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
    has_overrides TINYINT(1) NOT NULL DEFAULT 0,
    has_attendee_overrides TINYINT(1) NOT NULL DEFAULT 0,
    title VARCHAR(255) NULL,
    description TEXT NULL,
    starts_at_utc DATETIME NULL,
    ends_at_utc DATETIME NULL,
    all_day TINYINT(1) NULL,
    timezone VARCHAR(80) NULL,
    location VARCHAR(255) NULL,
    organizer_user_id BIGINT UNSIGNED NULL,
    contact_id BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, occurrence_start_utc),
    UNIQUE KEY uq_calendar_occurrence_company (event_id, occurrence_start_utc, company_id),
    KEY idx_calendar_occurrence_company_start (company_id, starts_at_utc, ends_at_utc, status),
    CONSTRAINT fk_calendar_occurrence_event FOREIGN KEY (event_id, company_id) REFERENCES calendar_events (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_occurrence_organizer FOREIGN KEY (organizer_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_occurrence_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_occurrence_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_calendar_occurrence_status CHECK (status IN ('scheduled','cancelled','completed')),
    CONSTRAINT chk_calendar_occurrence_flags CHECK (has_overrides IN (0,1) AND has_attendee_overrides IN (0,1)),
    CONSTRAINT chk_calendar_occurrence_overrides CHECK (
        (has_overrides = 0 AND title IS NULL AND description IS NULL AND starts_at_utc IS NULL AND ends_at_utc IS NULL AND all_day IS NULL AND timezone IS NULL AND location IS NULL AND organizer_user_id IS NULL AND contact_id IS NULL)
        OR
        (has_overrides = 1 AND title IS NOT NULL AND starts_at_utc IS NOT NULL AND ends_at_utc IS NOT NULL AND starts_at_utc < ends_at_utc AND all_day IS NOT NULL AND all_day IN (0,1) AND timezone IS NOT NULL AND location IS NOT NULL AND organizer_user_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_occurrence_user_attendees (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    occurrence_start_utc DATETIME NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    response_status VARCHAR(16) NOT NULL DEFAULT 'needs_action',
    PRIMARY KEY (event_id, occurrence_start_utc, user_id),
    KEY idx_calendar_occurrence_user (company_id, user_id, occurrence_start_utc),
    CONSTRAINT fk_calendar_occurrence_user_exception FOREIGN KEY (event_id, occurrence_start_utc, company_id) REFERENCES calendar_event_occurrence_exceptions (event_id, occurrence_start_utc, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_occurrence_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_calendar_occurrence_user_response CHECK (response_status IN ('needs_action','accepted','tentative','declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_occurrence_contact_attendees (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    occurrence_start_utc DATETIME NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    response_status VARCHAR(16) NOT NULL DEFAULT 'needs_action',
    PRIMARY KEY (event_id, occurrence_start_utc, contact_id),
    KEY idx_calendar_occurrence_contact (company_id, contact_id, occurrence_start_utc),
    CONSTRAINT fk_calendar_occurrence_contact_exception FOREIGN KEY (event_id, occurrence_start_utc, company_id) REFERENCES calendar_event_occurrence_exceptions (event_id, occurrence_start_utc, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_occurrence_attendee_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT chk_calendar_occurrence_contact_response CHECK (response_status IN ('needs_action','accepted','tentative','declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_occurrence_resources (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    occurrence_start_utc DATETIME NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, occurrence_start_utc, resource_id),
    KEY idx_calendar_occurrence_resource (company_id, resource_id, occurrence_start_utc),
    CONSTRAINT fk_calendar_occurrence_resource_exception FOREIGN KEY (event_id, occurrence_start_utc, company_id) REFERENCES calendar_event_occurrence_exceptions (event_id, occurrence_start_utc, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_occurrence_resource FOREIGN KEY (resource_id, company_id) REFERENCES appointment_resources (id, company_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
