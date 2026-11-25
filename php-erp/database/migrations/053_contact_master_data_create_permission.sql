INSERT INTO access_permissions (permission_key, module_key, description)
VALUES ('contact_master_data.create', 'contact_master_data', 'Create Contact master records')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Preserve the create capability previously bundled into Contacts write access.
INSERT IGNORE INTO role_permission_links (role_id, permission_id)
SELECT existing.role_id, create_permission.id
FROM role_permission_links existing
JOIN access_permissions contacts_write ON contacts_write.permission_key = 'contacts.write'
JOIN access_permissions create_permission ON create_permission.permission_key = 'contact_master_data.create'
WHERE existing.permission_id = contacts_write.id;
