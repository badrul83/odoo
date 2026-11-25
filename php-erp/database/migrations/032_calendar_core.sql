INSERT INTO access_permissions (permission_key, module_key, description) VALUES
    ('calendar.read', 'calendar', 'View company Calendar events'),
    ('calendar.write', 'calendar', 'Create, edit, and cancel Calendar events')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE TABLE IF NOT EXISTS calendar_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    starts_at_utc DATETIME NOT NULL,
    ends_at_utc DATETIME NOT NULL,
    timezone VARCHAR(80) NOT NULL,
    location VARCHAR(255) NOT NULL DEFAULT '',
    organizer_user_id BIGINT UNSIGNED NULL,
    contact_id BIGINT UNSIGNED NULL,
    source_model VARCHAR(40) NOT NULL DEFAULT 'manual',
    source_record_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendar_event_id_company (id, company_id),
    UNIQUE KEY uq_calendar_event_source (company_id, source_model, source_record_id),
    KEY idx_calendar_company_start (company_id, starts_at_utc, ends_at_utc, status),
    KEY idx_calendar_contact_start (company_id, contact_id, starts_at_utc),
    KEY idx_calendar_organizer_start (company_id, organizer_user_id, starts_at_utc),
    CONSTRAINT fk_calendar_event_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_event_organizer FOREIGN KEY (organizer_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_event_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_event_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_event_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_event_appointment FOREIGN KEY (source_record_id, company_id) REFERENCES appointment_bookings (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT chk_calendar_event_source CHECK ((source_model = 'manual' AND source_record_id IS NULL) OR (source_model = 'appointment' AND source_record_id IS NOT NULL)),
    CONSTRAINT chk_calendar_event_status CHECK (status IN ('scheduled','cancelled','completed','no_show')),
    CONSTRAINT chk_calendar_event_time CHECK (starts_at_utc < ends_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_event_user_attendees (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    response_status VARCHAR(16) NOT NULL DEFAULT 'needs_action',
    PRIMARY KEY (event_id, user_id),
    KEY idx_calendar_user_attendee_company (company_id, user_id, event_id),
    CONSTRAINT fk_calendar_user_attendee_event FOREIGN KEY (event_id, company_id) REFERENCES calendar_events (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_user_attendee_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_calendar_user_response CHECK (response_status IN ('needs_action','accepted','tentative','declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_event_contact_attendees (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    response_status VARCHAR(16) NOT NULL DEFAULT 'needs_action',
    PRIMARY KEY (event_id, contact_id),
    KEY idx_calendar_contact_attendee_company (company_id, contact_id, event_id),
    CONSTRAINT fk_calendar_contact_attendee_event FOREIGN KEY (event_id, company_id) REFERENCES calendar_events (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_contact_attendee_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT chk_calendar_contact_response CHECK (response_status IN ('needs_action','accepted','tentative','declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
