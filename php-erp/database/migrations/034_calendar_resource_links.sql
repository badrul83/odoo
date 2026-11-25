CREATE TABLE IF NOT EXISTS calendar_event_resources (
    company_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, resource_id),
    KEY idx_calendar_resource_company (company_id, resource_id, event_id),
    CONSTRAINT fk_calendar_event_resource_event FOREIGN KEY (event_id, company_id) REFERENCES calendar_events (id, company_id) ON DELETE RESTRICT,
    CONSTRAINT fk_calendar_event_resource_resource FOREIGN KEY (resource_id, company_id) REFERENCES appointment_resources (id, company_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
