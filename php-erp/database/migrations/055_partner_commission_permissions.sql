INSERT INTO access_permissions (permission_key, module_key, description)
VALUES
    ('partner_commissions.read', 'partner_commissions', 'View partner commission plans and assignments'),
    ('partner_commissions.assign', 'partner_commissions', 'Assign commission plans to Contacts')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Preserve existing Contact writers' ability to retain and assign commission plans.
INSERT IGNORE INTO role_permission_links (role_id, permission_id)
SELECT existing.role_id, commission_permission.id
FROM role_permission_links existing
JOIN access_permissions contacts_write ON contacts_write.permission_key = 'contacts.write'
JOIN access_permissions commission_permission ON commission_permission.permission_key IN ('partner_commissions.read', 'partner_commissions.assign')
WHERE existing.permission_id = contacts_write.id;
