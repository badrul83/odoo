INSERT INTO access_permissions (permission_key, module_key, description)
VALUES ('data_export.allowed', 'data_export', 'Export permitted master data records')
ON DUPLICATE KEY UPDATE description = VALUES(description);
