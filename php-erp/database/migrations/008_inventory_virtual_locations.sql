UPDATE stock_locations
SET location_usage = 'internal'
WHERE warehouse_id IS NOT NULL
  AND ((name = 'Input' AND location_usage = 'vendor') OR (name = 'Output' AND location_usage = 'customer'));

INSERT INTO stock_locations (company_id, warehouse_id, parent_location_id, name, complete_name, location_usage)
SELECT c.id, NULL, NULL, seed.name, seed.complete_name, seed.location_usage
FROM companies c
CROSS JOIN (
    SELECT 'Vendors' AS name, 'Partners/Vendors' AS complete_name, 'vendor' AS location_usage
    UNION ALL SELECT 'Customers', 'Partners/Customers', 'customer'
    UNION ALL SELECT 'Inventory Adjustment', 'Virtual Locations/Inventory Adjustment', 'inventory'
    UNION ALL SELECT 'Production', 'Virtual Locations/Production', 'production'
    UNION ALL SELECT 'Transit', 'Virtual Locations/Transit', 'transit'
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM stock_locations existing
    WHERE existing.company_id = c.id AND existing.warehouse_id IS NULL
      AND existing.name = seed.name AND existing.location_usage = seed.location_usage
);
