INSERT INTO access_permissions (permission_key, module_key, description)
VALUES ('products.create', 'products', 'Create product catalog records')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Preserve the product-create capability that the previous Inventory write permission included.
INSERT IGNORE INTO role_permission_links (role_id, permission_id)
SELECT existing.role_id, create_permission.id
FROM role_permission_links existing
JOIN access_permissions inventory_write ON inventory_write.permission_key = 'inventory.write'
JOIN access_permissions create_permission ON create_permission.permission_key = 'products.create'
WHERE existing.permission_id = inventory_write.id;
