<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;

final class InventoryRepository
{
    private const PRODUCT_FIELDS = [
        'company_id', 'category_id', 'uom_id', 'purchase_uom_id', 'name', 'internal_reference', 'barcode',
        'product_type', 'product_class', 'carton_size', 'units_per_carton', 'carton_price',
        'tracking', 'invoice_policy', 'sale_ok', 'purchase_ok', 'list_price', 'income_account_id', 'purchase_account_id', 'cost_price', 'weight', 'volume', 'description', 'active',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function options(UserContext $user, Authorization $authorization, ?int $companyId): array
    {
        $companies = array_values(array_filter(
            $this->allowedCompanies($user),
            fn (array $company): bool => $authorization->allowsForCompany('inventory.read', (int)$company['id'])
        ));
        if ($companyId === null) {
            $allowedIds = array_map(static fn (array $company): int => (int)$company['id'], $companies);
            if ($user->defaultCompanyId !== null && in_array($user->defaultCompanyId, $allowedIds, true)) {
                $companyId = $user->defaultCompanyId;
            } elseif (count($allowedIds) === 1) {
                $companyId = $allowedIds[0];
            } elseif (count($allowedIds) === 0) {
                throw new AccessDeniedException('You do not have Inventory read access for an allowed company.');
            } else {
                return [
                    'company_id' => null, 'companies' => $companies,
                    'categories' => [], 'category_records' => [], 'units' => [], 'unit_records' => [],
                    'uom_categories' => [], 'uom_category_records' => [], 'warehouses' => [], 'locations' => [],
                    'warehouse_records' => [], 'location_records' => [], 'contacts' => [], 'income_accounts' => [],
                    'purchase_accounts' => [], 'accounting_access' => false, 'product_create_access' => false,
                    'product_types' => [], 'tracking_options' => [], 'invoice_policies' => [],
                ];
            }
        }
        $companyId = $this->resolveCompany($user, $authorization, $companyId);
        if (!$authorization->allowsForCompany('inventory.read', $companyId)) {
            throw new AccessDeniedException('You do not have Inventory read access for this company.');
        }
        $productCreateAccess = $authorization->allowsForCompany('products.create', $companyId);
        $categories = $this->pdo->prepare(
            'SELECT c.id,c.name,c.parent_category_id,COALESCE(o.costing_method,s.costing_method,c.costing_method) AS costing_method,c.inventory_valuation FROM product_categories c ' .
            'LEFT JOIN inventory_accounting_settings s ON s.company_id=:settings_company ' .
            'LEFT JOIN product_category_accounting_overrides o ON o.company_id=:override_company AND o.category_id=c.id ' .
            'WHERE c.active=1 AND (c.company_id IS NULL OR c.company_id=:company_id) ORDER BY c.name,c.id'
        );
        $categories->execute(['settings_company' => $companyId, 'override_company' => $companyId, 'company_id' => $companyId]);
        $categoryRecords = $this->pdo->prepare(
            'SELECT c.id,c.company_id,c.name,c.parent_category_id,p.name AS parent_category_name,c.costing_method,c.inventory_valuation,c.active ' .
            'FROM product_categories c LEFT JOIN product_categories p ON p.id=c.parent_category_id ' .
            'WHERE c.company_id IS NULL OR c.company_id=:company_id ORDER BY c.active DESC,c.name,c.id'
        );
        $categoryRecords->execute(['company_id' => $companyId]);
        $units = $this->pdo->prepare(
            'SELECT u.id, u.name, u.abbreviation, u.category_id, c.name AS category_name, u.unit_type, ' .
            'u.factor_to_reference, u.rounding FROM units_of_measure u JOIN uom_categories c ON c.id = u.category_id ' .
            'WHERE u.active = 1 AND c.active = 1 AND (u.company_id IS NULL OR u.company_id = :unit_company_id) ' .
            'AND (c.company_id IS NULL OR c.company_id = :category_company_id) ORDER BY c.name, u.name, u.id'
        );
        $units->execute(['unit_company_id' => $companyId, 'category_company_id' => $companyId]);
        $unitRecords = $this->pdo->prepare(
            'SELECT u.id,u.company_id,u.category_id,u.name,u.abbreviation,u.unit_type,u.factor_to_reference,u.rounding,u.active,c.name AS category_name ' .
            'FROM units_of_measure u JOIN uom_categories c ON c.id=u.category_id ' .
            'WHERE (u.company_id IS NULL OR u.company_id=:unit_company_id) AND (c.company_id IS NULL OR c.company_id=:category_company_id) ' .
            'ORDER BY u.active DESC,c.name,u.name,u.id'
        );
        $unitRecords->execute(['unit_company_id' => $companyId, 'category_company_id' => $companyId]);
        $unitCategories = $this->pdo->prepare(
            'SELECT id, name, company_id, active FROM uom_categories ' .
            'WHERE active = 1 AND (company_id IS NULL OR company_id = :company_id) ORDER BY name, id'
        );
        $unitCategories->execute(['company_id' => $companyId]);
        $unitCategoryRecords = $this->pdo->prepare(
            'SELECT id,name,company_id,active FROM uom_categories WHERE company_id IS NULL OR company_id=:company_id ORDER BY active DESC,name,id'
        );
        $unitCategoryRecords->execute(['company_id' => $companyId]);
        $warehouses = $this->pdo->prepare(
            'SELECT id, name, code, partner_contact_id FROM warehouses WHERE company_id = :company_id AND active = 1 ORDER BY name, id'
        );
        $warehouses->execute(['company_id' => $companyId]);
        $locations = $this->pdo->prepare(
            'SELECT id, warehouse_id, parent_location_id, name, complete_name, location_usage, barcode ' .
            'FROM stock_locations WHERE company_id = :company_id AND active = 1 ORDER BY complete_name, id'
        );
        $locations->execute(['company_id' => $companyId]);
        $canReadContacts = $authorization->allowsForCompany('contacts.read', $companyId);
        $partnerIdField = $canReadContacts ? 'w.partner_contact_id' : 'NULL AS partner_contact_id';
        $partnerNameField = $canReadContacts ? 'c.name AS partner_contact_name' : 'NULL AS partner_contact_name';
        $partnerJoin = $canReadContacts ? 'LEFT JOIN contacts c ON c.id = w.partner_contact_id ' : '';
        $warehouseRecords = $this->pdo->prepare(
            'SELECT w.id, w.name, w.code, ' . $partnerIdField . ', ' . $partnerNameField . ', w.active ' .
            'FROM warehouses w ' . $partnerJoin .
            'WHERE w.company_id = :company_id ORDER BY w.active DESC, w.name, w.id'
        );
        $warehouseRecords->execute(['company_id' => $companyId]);
        $locationRecords = $this->pdo->prepare(
            'SELECT l.id, l.company_id, l.warehouse_id, l.parent_location_id, l.name, l.complete_name, l.location_usage, l.barcode, l.inventory_frequency_days, l.last_inventory_date, l.next_inventory_date, l.active, w.name AS warehouse_name ' .
            'FROM stock_locations l LEFT JOIN warehouses w ON w.id = l.warehouse_id ' .
            'WHERE l.company_id = :company_id ORDER BY l.active DESC, l.complete_name, l.id'
        );
        $locationRecords->execute(['company_id' => $companyId]);
        $contacts = [];
        if ($canReadContacts) {
            $contactQuery = $this->pdo->prepare(
                'SELECT id, name, email, contact_type, customer, supplier FROM contacts ' .
                'WHERE active = 1 AND (company_id IS NULL OR company_id = :company_id) ORDER BY name, id LIMIT 1000'
            );
            $contactQuery->execute(['company_id' => $companyId]);
            $contacts = $contactQuery->fetchAll();
        }
        $accountingAccess = $authorization->allowsForCompany('accounting.read', $companyId);
        $incomeAccounts = [];
        $purchaseAccounts = [];
        if ($accountingAccess) {
            $accounts = $this->pdo->prepare("SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company_id AND active=1 AND account_type LIKE 'income%' ORDER BY code,id");
            $accounts->execute(['company_id' => $companyId]);
            $incomeAccounts = $accounts->fetchAll();
            $accounts = $this->pdo->prepare("SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company_id AND active=1 AND account_type IN ('asset_current','expense','expense_other','expense_direct_cost') ORDER BY code,id");
            $accounts->execute(['company_id' => $companyId]);
            $purchaseAccounts = $accounts->fetchAll();
        }
        return [
            'company_id' => $companyId,
            'companies' => $companies,
            'categories' => $categories->fetchAll(),
            'category_records' => $categoryRecords->fetchAll(),
            'units' => $units->fetchAll(),
            'unit_records' => $unitRecords->fetchAll(),
            'uom_categories' => $unitCategories->fetchAll(),
            'uom_category_records' => $unitCategoryRecords->fetchAll(),
            'warehouses' => $warehouses->fetchAll(),
            'locations' => $locations->fetchAll(),
            'warehouse_records' => $warehouseRecords->fetchAll(),
            'location_records' => $locationRecords->fetchAll(),
            'contacts' => $contacts,
            'income_accounts' => $incomeAccounts,
            'purchase_accounts' => $purchaseAccounts,
            'accounting_access' => $accountingAccess,
            'product_create_access' => $productCreateAccess,
            'product_types' => [
                ['value' => 'goods', 'name' => 'Goods'],
                ['value' => 'service', 'name' => 'Service'],
                ['value' => 'combo', 'name' => 'Combo'],
            ],
            'tracking_options' => [
                ['value' => 'none', 'name' => 'By Quantity'],
                ['value' => 'lot', 'name' => 'By Lots'],
                ['value' => 'serial', 'name' => 'By Unique Serial Number'],
            ],
            'invoice_policies' => [
                ['value' => 'ordered', 'name' => 'Ordered quantities'],
                ['value' => 'delivered', 'name' => 'Delivered quantities'],
            ],
        ];
    }

    public function productOptions(UserContext $user, Authorization $authorization, ?int $companyId): array
    {
        $companies = array_values(array_filter(
            $this->allowedCompanies($user),
            fn (array $company): bool => $authorization->allowsForCompany('products.create', (int)$company['id'])
        ));
        if ($companyId === null) {
            $allowedIds = array_map(static fn (array $company): int => (int)$company['id'], $companies);
            if ($user->defaultCompanyId !== null && in_array($user->defaultCompanyId, $allowedIds, true)) {
                $companyId = $user->defaultCompanyId;
            } elseif (count($allowedIds) === 1) {
                $companyId = $allowedIds[0];
            } elseif (count($allowedIds) === 0) {
                throw new AccessDeniedException('You do not have Products access for an allowed company.');
            } else {
                return [
                    'company_id' => null,
                    'companies' => $companies,
                    'categories' => [],
                    'units' => [],
                    'income_accounts' => [],
                    'purchase_accounts' => [],
                    'accounting_access' => false,
                    'product_create_access' => false,
                    'product_types' => [],
                    'tracking_options' => [],
                    'invoice_policies' => [],
                ];
            }
        }
        $companyId = $this->resolveCompany($user, $authorization, $companyId);
        if (!$authorization->allowsForCompany('products.create', $companyId)) {
            throw new AccessDeniedException('You do not have Products create access for this company.');
        }

        $categories = $this->pdo->prepare(
            'SELECT id,name FROM product_categories WHERE active=1 AND (company_id IS NULL OR company_id=:company_id) ORDER BY name,id'
        );
        $categories->execute(['company_id' => $companyId]);
        $units = $this->pdo->prepare(
            'SELECT u.id,u.name,u.abbreviation,u.category_id,c.name AS category_name ' .
            'FROM units_of_measure u JOIN uom_categories c ON c.id=u.category_id ' .
            'WHERE u.active=1 AND c.active=1 AND (u.company_id IS NULL OR u.company_id=:unit_company) ' .
            'AND (c.company_id IS NULL OR c.company_id=:category_company) ORDER BY c.name,u.name,u.id'
        );
        $units->execute(['unit_company' => $companyId, 'category_company' => $companyId]);

        $incomeAccounts = [];
        $purchaseAccounts = [];
        if ($authorization->allowsForCompany('accounting.read', $companyId)) {
            $query = $this->pdo->prepare("SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company_id AND active=1 AND account_type LIKE 'income%' ORDER BY code,id");
            $query->execute(['company_id' => $companyId]);
            $incomeAccounts = $query->fetchAll();
            $query = $this->pdo->prepare("SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company_id AND active=1 AND account_type IN ('asset_current','expense','expense_other','expense_direct_cost') ORDER BY code,id");
            $query->execute(['company_id' => $companyId]);
            $purchaseAccounts = $query->fetchAll();
        }

        return [
            'company_id' => $companyId,
            'companies' => $companies,
            'categories' => $categories->fetchAll(),
            'units' => $units->fetchAll(),
            'income_accounts' => $incomeAccounts,
            'purchase_accounts' => $purchaseAccounts,
            'accounting_access' => $authorization->allowsForCompany('accounting.read', $companyId),
            'product_create_access' => $authorization->allowsForCompany('products.create', $companyId),
            'product_types' => [
                ['value' => 'goods', 'name' => 'Goods'],
                ['value' => 'service', 'name' => 'Service'],
                ['value' => 'combo', 'name' => 'Combo'],
            ],
            'tracking_options' => [
                ['value' => 'none', 'name' => 'By Quantity'],
                ['value' => 'lot', 'name' => 'By Lots'],
                ['value' => 'serial', 'name' => 'By Unique Serial Number'],
            ],
            'invoice_policies' => [
                ['value' => 'ordered', 'name' => 'Ordered quantities'],
                ['value' => 'delivered', 'name' => 'Delivered quantities'],
            ],
        ];
    }

    public function createProductCategory(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'name', 'parent_category_id', 'costing_method', 'inventory_valuation']);
        if ($unknown) throw new InvalidArgumentException('Unsupported product category fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $name = trim((string)($input['name'] ?? ''));
        $parentId = $this->nullablePositiveId($input['parent_category_id'] ?? null, 'Parent Product Category');
        $costing = (string)($input['costing_method'] ?? 'fifo');
        $valuation = (string)($input['inventory_valuation'] ?? 'manual');
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Product category name is required and cannot exceed 120 characters.');
        if (!in_array($costing, ['standard', 'fifo', 'average'], true)) throw new InvalidArgumentException('Choose Standard Price, FIFO, or Average Cost for this category.');
        if (!in_array($valuation, ['manual', 'real_time'], true)) throw new InvalidArgumentException('Choose Manual or Automated inventory valuation.');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $this->assertProductCategoryParent($parentId, 0, $companyId);
            $insert = $this->pdo->prepare('INSERT INTO product_categories (company_id,parent_category_id,name,costing_method,inventory_valuation) VALUES (:company,:parent,:name,:costing,:valuation)');
            $insert->execute(['company' => $companyId, 'parent' => $parentId, 'name' => $name, 'costing' => $costing, 'valuation' => $valuation]);
            $id = (int)$this->pdo->lastInsertId();
            $record = ['id' => $id, 'company_id' => $companyId, 'parent_category_id' => $parentId, 'name' => $name, 'costing_method' => $costing, 'inventory_valuation' => $valuation, 'active' => 1];
            $this->audit($companyId, $actorId, $id, 'inventory.product_category.created', null, $record, $remoteAddress, 'inventory.product.category');
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function createUomCategory(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'name']);
        if ($unknown) throw new InvalidArgumentException('Unsupported unit category fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Unit category name is required and cannot exceed 100 characters.');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $insert = $this->pdo->prepare('INSERT INTO uom_categories (company_id,name) VALUES (:company,:name)');
            $insert->execute(['company' => $companyId, 'name' => $name]);
            $id = (int)$this->pdo->lastInsertId();
            $record = ['id' => $id, 'company_id' => $companyId, 'name' => $name, 'active' => 1];
            $this->audit($companyId, $actorId, $id, 'inventory.uom_category.created', null, $record, $remoteAddress, 'inventory.uom.category');
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function createUom(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'category_id', 'name', 'abbreviation', 'unit_type', 'factor_to_reference', 'rounding']);
        if ($unknown) throw new InvalidArgumentException('Unsupported unit of measure fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $categoryId = $this->positiveId($input['category_id'] ?? null, 'Unit Category');
        $name = trim((string)($input['name'] ?? ''));
        $abbreviation = trim((string)($input['abbreviation'] ?? ''));
        $unitType = (string)($input['unit_type'] ?? 'reference');
        $factor = $this->conversionDecimal($input['factor_to_reference'] ?? null, 'Conversion Factor');
        $rounding = $this->conversionDecimal($input['rounding'] ?? null, 'Rounding Precision');
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Unit name is required and cannot exceed 100 characters.');
        if ($abbreviation === '' || mb_strlen($abbreviation) > 24) throw new InvalidArgumentException('Unit abbreviation is required and cannot exceed 24 characters.');
        if (!in_array($unitType, ['reference', 'bigger', 'smaller'], true)) throw new InvalidArgumentException('Choose Reference Unit, Bigger than the reference unit, or Smaller than the reference unit.');
        $factorNumber = (float)$factor;
        if ((float)$rounding <= 0) throw new InvalidArgumentException('Rounding precision must be greater than zero.');
        if (($unitType === 'reference' && !$this->sameConversionDecimal($factor, '1')) || ($unitType === 'bigger' && $factorNumber <= 1) || ($unitType === 'smaller' && ($factorNumber <= 0 || $factorNumber >= 1))) {
            throw new InvalidArgumentException('The conversion factor must be 1 for a Reference unit, greater than 1 for a Bigger unit, and between 0 and 1 for a Smaller unit.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $category = $this->pdo->prepare('SELECT company_id FROM uom_categories WHERE id=:id AND active=1 FOR UPDATE');
            $category->execute(['id' => $categoryId]);
            $categoryCompany = $category->fetchColumn();
            if ($categoryCompany === false || ($categoryCompany !== null && (int)$categoryCompany !== $companyId)) throw new InvalidArgumentException('Select an active unit category available to this company.');
            if ($unitType === 'reference') {
                $reference = $this->pdo->prepare("SELECT id FROM units_of_measure WHERE category_id=:category AND active=1 AND unit_type='reference' AND (company_id IS NULL OR company_id=:company) LIMIT 1");
                $reference->execute(['category' => $categoryId, 'company' => $companyId]);
                if ($reference->fetchColumn() !== false) throw new InvalidArgumentException('This unit category already has an active Reference unit. Create a Bigger or Smaller unit instead.');
            }
            $insert = $this->pdo->prepare('INSERT INTO units_of_measure (company_id,category_id,name,abbreviation,unit_type,factor_to_reference,rounding) VALUES (:company,:category,:name,:abbreviation,:unit_type,:factor,:rounding)');
            $insert->execute(['company' => $companyId, 'category' => $categoryId, 'name' => $name, 'abbreviation' => $abbreviation, 'unit_type' => $unitType, 'factor' => $factor, 'rounding' => $rounding]);
            $id = (int)$this->pdo->lastInsertId();
            $record = ['id' => $id, 'company_id' => $companyId, 'category_id' => $categoryId, 'name' => $name, 'abbreviation' => $abbreviation, 'unit_type' => $unitType, 'factor_to_reference' => $factor, 'rounding' => $rounding, 'active' => 1];
            $this->audit($companyId, $actorId, $id, 'inventory.uom.created', null, $record, $remoteAddress, 'inventory.uom');
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function updateProductCategory(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'name', 'parent_category_id', 'costing_method', 'inventory_valuation']);
        if ($unknown) throw new InvalidArgumentException('Unsupported product category fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $name = trim((string)($input['name'] ?? ''));
        $parentId = $this->nullablePositiveId($input['parent_category_id'] ?? null, 'Parent Product Category');
        $costing = (string)($input['costing_method'] ?? 'fifo');
        $valuation = (string)($input['inventory_valuation'] ?? 'manual');
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Product category name is required and cannot exceed 120 characters.');
        if (!in_array($costing, ['standard', 'fifo', 'average'], true)) throw new InvalidArgumentException('Choose Standard Price, FIFO, or Average Cost for this category.');
        if (!in_array($valuation, ['manual', 'real_time'], true)) throw new InvalidArgumentException('Choose Manual or Automated inventory valuation.');

        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockCompanyMasterRecord('product_categories', $id, $companyId);
            $this->assertProductCategoryParent($parentId, $id, $companyId);
            if (($costing !== $before['costing_method'] || $valuation !== $before['inventory_valuation']) && $this->hasCategoryPolicyReferences($id)) {
                throw new InvalidArgumentException('Costing and inventory valuation cannot be changed after this category has been used. Create a new category and reassign active products instead.');
            }
            $update = $this->pdo->prepare('UPDATE product_categories SET parent_category_id=:parent,name=:name,costing_method=:costing,inventory_valuation=:valuation WHERE id=:id AND company_id=:company');
            $update->execute(['parent' => $parentId, 'name' => $name, 'costing' => $costing, 'valuation' => $valuation, 'id' => $id, 'company' => $companyId]);
            $after = $before;
            $after['parent_category_id'] = $parentId;
            $after['name'] = $name;
            $after['costing_method'] = $costing;
            $after['inventory_valuation'] = $valuation;
            $this->audit($companyId, $actorId, $id, 'inventory.product_category.updated', $before, $after, $remoteAddress, 'inventory.product.category');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setProductCategoryActive(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported product category status fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $active = $this->boolean($input['active'] ?? null, 'Active');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockCompanyMasterRecord('product_categories', $id, $companyId);
            if ((int)$before['active'] === $active) { $this->pdo->commit(); return $before; }
            if ($active) {
                $this->assertProductCategoryParent($before['parent_category_id'] === null ? null : (int)$before['parent_category_id'], $id, $companyId);
            } else {
                $products = $this->pdo->prepare('SELECT 1 FROM products WHERE company_id=:company AND category_id=:category AND active=1 LIMIT 1');
                $products->execute(['company' => $companyId, 'category' => $id]);
                if ($products->fetchColumn()) throw new InvalidArgumentException('Reassign or archive active products in this category before archiving it.');
                $children = $this->pdo->prepare('SELECT 1 FROM product_categories WHERE parent_category_id=:category AND active=1 AND (company_id IS NULL OR company_id=:company) LIMIT 1');
                $children->execute(['category' => $id, 'company' => $companyId]);
                if ($children->fetchColumn()) throw new InvalidArgumentException('Archive or reassign active child categories before archiving this category.');
            }
            $this->pdo->prepare('UPDATE product_categories SET active=:active WHERE id=:id AND company_id=:company')->execute(['active' => $active, 'id' => $id, 'company' => $companyId]);
            $after = $before; $after['active'] = $active;
            $event = $active ? 'inventory.product_category.restored' : 'inventory.product_category.archived';
            $this->audit($companyId, $actorId, $id, $event, $before, $after, $remoteAddress, 'inventory.product.category');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function updateUomCategory(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'name']);
        if ($unknown) throw new InvalidArgumentException('Unsupported unit category fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Unit category name is required and cannot exceed 100 characters.');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockCompanyMasterRecord('uom_categories', $id, $companyId);
            $this->pdo->prepare('UPDATE uom_categories SET name=:name WHERE id=:id AND company_id=:company')->execute(['name' => $name, 'id' => $id, 'company' => $companyId]);
            $after = $before; $after['name'] = $name;
            $this->audit($companyId, $actorId, $id, 'inventory.uom_category.updated', $before, $after, $remoteAddress, 'inventory.uom.category');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setUomCategoryActive(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported unit category status fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $active = $this->boolean($input['active'] ?? null, 'Active');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockCompanyMasterRecord('uom_categories', $id, $companyId);
            if ((int)$before['active'] === $active) { $this->pdo->commit(); return $before; }
            if (!$active) {
                $units = $this->pdo->prepare('SELECT 1 FROM units_of_measure WHERE category_id=:category AND active=1 AND (company_id IS NULL OR company_id=:company) LIMIT 1');
                $units->execute(['category' => $id, 'company' => $companyId]);
                if ($units->fetchColumn()) throw new InvalidArgumentException('Archive or move active Units of Measure before archiving this unit category.');
            }
            $this->pdo->prepare('UPDATE uom_categories SET active=:active WHERE id=:id AND company_id=:company')->execute(['active' => $active, 'id' => $id, 'company' => $companyId]);
            $after = $before; $after['active'] = $active;
            $event = $active ? 'inventory.uom_category.restored' : 'inventory.uom_category.archived';
            $this->audit($companyId, $actorId, $id, $event, $before, $after, $remoteAddress, 'inventory.uom.category');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function updateUom(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'category_id', 'name', 'abbreviation', 'unit_type', 'factor_to_reference', 'rounding']);
        if ($unknown) throw new InvalidArgumentException('Unsupported unit of measure fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $categoryId = $this->positiveId($input['category_id'] ?? null, 'Unit Category');
        $name = trim((string)($input['name'] ?? ''));
        $abbreviation = trim((string)($input['abbreviation'] ?? ''));
        $unitType = (string)($input['unit_type'] ?? 'reference');
        $factor = $this->conversionDecimal($input['factor_to_reference'] ?? null, 'Conversion Factor');
        $rounding = $this->conversionDecimal($input['rounding'] ?? null, 'Rounding Precision');
        if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Unit name is required and cannot exceed 100 characters.');
        if ($abbreviation === '' || mb_strlen($abbreviation) > 24) throw new InvalidArgumentException('Unit abbreviation is required and cannot exceed 24 characters.');
        if (!in_array($unitType, ['reference', 'bigger', 'smaller'], true)) throw new InvalidArgumentException('Choose Reference Unit, Bigger than the reference unit, or Smaller than the reference unit.');
        $factorNumber = (float)$factor;
        if ((float)$rounding <= 0) throw new InvalidArgumentException('Rounding precision must be greater than zero.');
        if (($unitType === 'reference' && !$this->sameConversionDecimal($factor, '1')) || ($unitType === 'bigger' && $factorNumber <= 1) || ($unitType === 'smaller' && ($factorNumber <= 0 || $factorNumber >= 1))) {
            throw new InvalidArgumentException('The conversion factor must be 1 for a Reference unit, greater than 1 for a Bigger unit, and between 0 and 1 for a Smaller unit.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockCompanyMasterRecord('units_of_measure', $id, $companyId);
            $category = $this->pdo->prepare('SELECT company_id FROM uom_categories WHERE id=:id AND active=1 FOR UPDATE');
            $category->execute(['id' => $categoryId]);
            $categoryCompany = $category->fetchColumn();
            if ($categoryCompany === false || ($categoryCompany !== null && (int)$categoryCompany !== $companyId)) throw new InvalidArgumentException('Select an active unit category available to this company.');
            $conversionChanged = (int)$before['category_id'] !== $categoryId || $before['unit_type'] !== $unitType || !$this->sameConversionDecimal((string)$before['factor_to_reference'], $factor) || !$this->sameConversionDecimal((string)$before['rounding'], $rounding);
            if ($conversionChanged && $this->hasForeignKeyReferences('units_of_measure', $id)) throw new InvalidArgumentException('Category, unit type, conversion factor, and rounding are locked after this unit has been used. Update only its name or abbreviation to preserve historical quantities.');
            if ((int)$before['active'] === 1 && $unitType === 'reference') {
                $reference = $this->pdo->prepare("SELECT id FROM units_of_measure WHERE category_id=:category AND id<>:id AND active=1 AND unit_type='reference' AND (company_id IS NULL OR company_id=:company) LIMIT 1");
                $reference->execute(['category' => $categoryId, 'id' => $id, 'company' => $companyId]);
                if ($reference->fetchColumn() !== false) throw new InvalidArgumentException('This unit category already has an active Reference unit. Create a Bigger or Smaller unit instead.');
            }
            $this->pdo->prepare('UPDATE units_of_measure SET category_id=:category,name=:name,abbreviation=:abbreviation,unit_type=:unit_type,factor_to_reference=:factor,rounding=:rounding WHERE id=:id AND company_id=:company')
                ->execute(['category' => $categoryId, 'name' => $name, 'abbreviation' => $abbreviation, 'unit_type' => $unitType, 'factor' => $factor, 'rounding' => $rounding, 'id' => $id, 'company' => $companyId]);
            $after = $before;
            $after['category_id'] = $categoryId; $after['name'] = $name; $after['abbreviation'] = $abbreviation;
            $after['unit_type'] = $unitType; $after['factor_to_reference'] = $factor; $after['rounding'] = $rounding;
            $this->audit($companyId, $actorId, $id, 'inventory.uom.updated', $before, $after, $remoteAddress, 'inventory.uom');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setUomActive(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported unit status fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $active = $this->boolean($input['active'] ?? null, 'Active');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockCompanyMasterRecord('units_of_measure', $id, $companyId);
            if ((int)$before['active'] === $active) { $this->pdo->commit(); return $before; }
            if ($active) {
                $category = $this->pdo->prepare('SELECT company_id FROM uom_categories WHERE id=:id AND active=1 FOR UPDATE');
                $category->execute(['id' => $before['category_id']]);
                $categoryCompany = $category->fetchColumn();
                if ($categoryCompany === false || ($categoryCompany !== null && (int)$categoryCompany !== $companyId)) throw new InvalidArgumentException('Restore the unit category before restoring this Unit of Measure.');
                if ($before['unit_type'] === 'reference') {
                    $reference = $this->pdo->prepare("SELECT id FROM units_of_measure WHERE category_id=:category AND id<>:id AND active=1 AND unit_type='reference' AND (company_id IS NULL OR company_id=:company) LIMIT 1");
                    $reference->execute(['category' => $before['category_id'], 'id' => $id, 'company' => $companyId]);
                    if ($reference->fetchColumn() !== false) throw new InvalidArgumentException('This unit category already has an active Reference unit. Archive the other Reference unit first.');
                }
            } elseif ($this->hasForeignKeyReferences('units_of_measure', $id)) {
                throw new InvalidArgumentException('This Unit of Measure is linked to existing records and cannot be archived. Keep it active so saved quantities and document lines remain valid.');
            }
            $this->pdo->prepare('UPDATE units_of_measure SET active=:active WHERE id=:id AND company_id=:company')->execute(['active' => $active, 'id' => $id, 'company' => $companyId]);
            $after = $before; $after['active'] = $active;
            $event = $active ? 'inventory.uom.restored' : 'inventory.uom.archived';
            $this->audit($companyId, $actorId, $id, $event, $before, $after, $remoteAddress, 'inventory.uom');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function lockCompanyMasterRecord(string $table, int $id, int $companyId): array
    {
        if (!in_array($table, ['product_categories', 'uom_categories', 'units_of_measure'], true)) throw new \LogicException('Unsupported Inventory master-data table.');
        $query = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE id=:id AND company_id=:company LIMIT 1 FOR UPDATE');
        $query->execute(['id' => $id, 'company' => $companyId]);
        $record = $query->fetch();
        if (!$record) {
            $shared = $this->pdo->prepare('SELECT company_id FROM ' . $table . ' WHERE id=:id LIMIT 1');
            $shared->execute(['id' => $id]);
            $owner = $shared->fetchColumn();
            if ($owner === false) throw new InvalidArgumentException('The Inventory master record was not found.');
            if ($owner === null) throw new InvalidArgumentException('Shared Inventory master records are read-only. Create a company-specific record to customize it.');
            throw new InvalidArgumentException('The Inventory master record was not found in the selected company.');
        }
        return $record;
    }

    private function assertProductCategoryParent(?int $parentId, int $categoryId, int $companyId): void
    {
        if ($parentId === null) return;
        $cursor = $parentId;
        $visited = [];
        for ($depth = 0; $cursor !== null && $depth < 1000; $depth++) {
            if ($cursor === $categoryId) throw new InvalidArgumentException('A product category cannot be its own parent or a descendant of itself.');
            if (isset($visited[$cursor])) throw new InvalidArgumentException('The parent hierarchy contains a cycle and must be corrected first.');
            $visited[$cursor] = true;
            $query = $this->pdo->prepare('SELECT parent_category_id,company_id,active FROM product_categories WHERE id=:id LIMIT 1');
            $query->execute(['id' => $cursor]);
            $row = $query->fetch();
            if (!$row || !(int)$row['active'] || ($row['company_id'] !== null && (int)$row['company_id'] !== $companyId)) throw new InvalidArgumentException('Select an active parent category available to this company.');
            $cursor = $row['parent_category_id'] === null ? null : (int)$row['parent_category_id'];
        }
        if ($cursor !== null) throw new InvalidArgumentException('The product category hierarchy is too deep to validate safely.');
    }

    private function hasCategoryPolicyReferences(int $categoryId): bool
    {
        $products = $this->pdo->prepare('SELECT 1 FROM products WHERE category_id=:category LIMIT 1');
        $products->execute(['category' => $categoryId]);
        if ($products->fetchColumn()) return true;
        $overrides = $this->pdo->prepare('SELECT 1 FROM product_category_accounting_overrides WHERE category_id=:category LIMIT 1');
        $overrides->execute(['category' => $categoryId]);
        return (bool)$overrides->fetchColumn();
    }

    private function hasForeignKeyReferences(string $referencedTable, int $id): bool
    {
        if (!in_array($referencedTable, ['units_of_measure'], true)) throw new \LogicException('Unsupported Inventory master-data reference check.');
        $references = $this->pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=:table AND REFERENCED_COLUMN_NAME='id'");
        $references->execute(['table' => $referencedTable]);
        foreach ($references->fetchAll() as $reference) {
            $table = (string)$reference['TABLE_NAME'];
            $column = (string)$reference['COLUMN_NAME'];
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || !preg_match('/^[A-Za-z0-9_]+$/D', $column)) throw new \LogicException('The database returned an invalid foreign-key identifier.');
            $query = $this->pdo->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $column . '`=:id LIMIT 1');
            $query->execute(['id' => $id]);
            if ($query->fetchColumn()) return true;
        }
        return false;
    }

    private function masterCompanyId(array $input, UserContext $user, Authorization $authorization): int
    {
        $companyId = $this->positiveId($input['company_id'] ?? $user->defaultCompanyId, 'Company');
        if (!$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('The selected company is not available to this user.');
        if (!$authorization->allowsForCompany('inventory.write', $companyId)) throw new AccessDeniedException('You do not have Inventory write access for this company.');
        return $companyId;
    }

    private function lockMasterCompany(int $companyId): void
    {
        $lock = $this->pdo->prepare('SELECT id FROM companies WHERE id=:company FOR UPDATE');
        $lock->execute(['company' => $companyId]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('The selected company no longer exists.');
    }

    private function conversionDecimal(mixed $value, string $label): string
    {
        $text = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,10})?$/D', $text) !== 1) throw new InvalidArgumentException($label . ' must be a non-negative decimal with up to 10 decimal places.');
        return $text;
    }

    private function sameConversionDecimal(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $whole = ltrim($whole, '0');
            $whole = $whole === '' ? '0' : $whole;
            $fraction = rtrim($fraction, '0');
            return $fraction === '' ? $whole : $whole . '.' . $fraction;
        };
        return $normalize($left) === $normalize($right);
    }

    public function search(UserContext $user, Authorization $authorization, array $filters): array
    {
        $rawCompanyId = $filters['company_id'] ?? null;
        $companyId = $rawCompanyId === null || $rawCompanyId === '' ? null : $this->positiveId($rawCompanyId, 'Company');
        $companyId = $this->resolveCompany($user, $authorization, $companyId);
        if (!$authorization->allowsForCompany('inventory.read', $companyId)) {
            throw new AccessDeniedException('You do not have Products access for this company.');
        }
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $where = ['p.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        $status = (string)($filters['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = 'p.active = 1';
        } elseif ($status === 'archived') {
            $where[] = 'p.active = 0';
        } elseif ($status !== 'all') {
            throw new InvalidArgumentException('Product status must be active, archived, or all.');
        }
        $queryText = trim((string)($filters['q'] ?? ''));
        if ($queryText !== '') {
            if (strlen($queryText) > 190) {
                throw new InvalidArgumentException('Product search text must be 190 characters or fewer.');
            }
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queryText) . '%';
            $where[] = '(p.name LIKE :q_name ESCAPE \'!\' OR p.internal_reference LIKE :q_reference ESCAPE \'!\' OR p.barcode LIKE :q_barcode ESCAPE \'!\')';
            $params += ['q_name' => $like, 'q_reference' => $like, 'q_barcode' => $like];
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM products p WHERE ' . $whereSql);
        $count->execute($params);
        $list = $this->pdo->prepare(
            'SELECT p.id, p.company_id, p.category_id, c.name AS category_name, p.uom_id, u.name AS uom_name, ' .
            'p.purchase_uom_id, pu.name AS purchase_uom_name, p.name, p.internal_reference, p.barcode, p.product_type, p.product_class, p.carton_size, p.units_per_carton, p.carton_price, ' .
            'p.tracking, p.invoice_policy, p.sale_ok, p.purchase_ok, p.list_price, p.income_account_id, p.purchase_account_id, p.cost_price, p.weight, p.volume, p.active, p.updated_at, ' .
            'COALESCE((SELECT SUM(q.quantity) FROM stock_quants q JOIN stock_locations sl ON sl.id=q.location_id ' .
            'WHERE q.company_id=p.company_id AND q.product_id=p.id AND sl.location_usage IN (\'internal\',\'transit\')),0) AS on_hand_quantity ' .
            'FROM products p JOIN product_categories c ON c.id = p.category_id ' .
            'JOIN units_of_measure u ON u.id = p.uom_id JOIN units_of_measure pu ON pu.id = p.purchase_uom_id ' .
            'WHERE ' . $whereSql . ' ORDER BY p.name, p.id LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $list->bindValue(':limit', $limit, PDO::PARAM_INT);
        $list->bindValue(':offset', $offset, PDO::PARAM_INT);
        $list->execute();
        return ['records' => $list->fetchAll(), 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId];
    }

    public function catalog(UserContext $user, Authorization $authorization, array $filters): array
    {
        $channel = (string)($filters['channel'] ?? 'inventory');
        $permission = match ($channel) {
            'inventory' => 'inventory.read',
            'purchase' => 'purchase.read',
            'sales' => 'sales.read',
            'invoice' => 'invoicing.read',
            default => throw new InvalidArgumentException('Catalog channel must be inventory, purchase, sales, or invoice.'),
        };
        $rawCompanyId = $filters['company_id'] ?? null;
        $companyId = $rawCompanyId === null || $rawCompanyId === '' ? null : $this->positiveId($rawCompanyId, 'Company');
        $companyId = $this->resolveCompany($user, $authorization, $companyId);
        if (!$authorization->allowsForCompany($permission, $companyId)) {
            throw new AccessDeniedException('You do not have access to this product catalog for the selected company.');
        }

        $limit = max(1, min(100, (int)($filters['limit'] ?? 40)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $where = ['p.company_id = :company_id', 'p.active = 1'];
        $params = ['company_id' => $companyId];
        if ($channel === 'purchase') $where[] = 'p.purchase_ok = 1';
        if (in_array($channel, ['sales', 'invoice'], true)) $where[] = 'p.sale_ok = 1';
        if ($channel === 'invoice') $where[] = "p.product_type = 'service'";
        $productType = (string)($filters['product_type'] ?? '');
        if ($productType !== '') {
            if (!in_array($productType, ['goods', 'service', 'combo'], true)) throw new InvalidArgumentException('Product type must be Goods, Service, or Combo.');
            $where[] = 'p.product_type = :product_type';
            $params['product_type'] = $productType;
        }

        $queryText = trim((string)($filters['q'] ?? ''));
        if ($queryText !== '') {
            if (strlen($queryText) > 190) throw new InvalidArgumentException('Product search text must be 190 characters or fewer.');
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queryText) . '%';
            $where[] = '(p.name LIKE :q_name ESCAPE \'!\' OR p.internal_reference LIKE :q_reference ESCAPE \'!\' OR p.barcode LIKE :q_barcode ESCAPE \'!\' OR c.name LIKE :q_category ESCAPE \'!\')';
            $params += ['q_name' => $like, 'q_reference' => $like, 'q_barcode' => $like, 'q_category' => $like];
        }
        $categoryId = $filters['category_id'] ?? null;
        if ($categoryId !== null && $categoryId !== '') {
            $params['category_id'] = $this->positiveId($categoryId, 'Category');
            $where[] = 'p.category_id = :category_id';
        }
        $whereSql = implode(' AND ', $where);
        $categoryWhere = ['p.company_id = :category_company_id', 'p.active = 1', 'c.active = 1'];
        $categoryParams = ['category_company_id' => $companyId];
        if ($channel === 'purchase') $categoryWhere[] = 'p.purchase_ok = 1';
        if (in_array($channel, ['sales', 'invoice'], true)) $categoryWhere[] = 'p.sale_ok = 1';
        if ($channel === 'invoice') $categoryWhere[] = "p.product_type = 'service'";
        if ($productType !== '') {
            $categoryWhere[] = 'p.product_type = :category_product_type';
            $categoryParams['category_product_type'] = $productType;
        }
        $categories = $this->pdo->prepare(
            'SELECT DISTINCT c.id,c.name FROM products p JOIN product_categories c ON c.id=p.category_id WHERE ' .
            implode(' AND ', $categoryWhere) . ' ORDER BY c.name,c.id'
        );
        $categories->execute($categoryParams);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM products p JOIN product_categories c ON c.id=p.category_id WHERE ' . $whereSql);
        $count->execute($params);

        $priceFields = match ($channel) {
            'inventory' => 'p.list_price,p.cost_price,p.carton_price',
            'purchase' => 'p.cost_price,p.carton_price',
            default => 'p.list_price,p.carton_price',
        };
        $list = $this->pdo->prepare(
            'SELECT p.id,p.company_id,p.category_id,c.name AS category_name,p.name,p.internal_reference,p.barcode,p.product_type,p.product_class,' .
            'p.carton_size,p.units_per_carton,p.tracking,p.invoice_policy,p.sale_ok,p.purchase_ok,p.active,p.uom_id,u.name AS uom_name,u.abbreviation AS uom_abbreviation,' .
            'p.purchase_uom_id,pu.name AS purchase_uom_name,pu.abbreviation AS purchase_uom_abbreviation,' . $priceFields . ',' .
            'COALESCE((SELECT SUM(q.quantity) FROM stock_quants q JOIN stock_locations sl ON sl.id=q.location_id ' .
            'WHERE q.company_id=p.company_id AND q.product_id=p.id AND sl.location_usage IN (\'internal\',\'transit\')),0) AS on_hand_quantity,' .
            'COALESCE((SELECT SUM(CASE WHEN sm.demand_quantity>sm.done_quantity THEN sm.demand_quantity-sm.done_quantity ELSE 0 END) ' .
            'FROM stock_moves sm JOIN stock_locations src ON src.id=sm.source_location_id JOIN stock_locations dst ON dst.id=sm.destination_location_id ' .
            'WHERE sm.company_id=p.company_id AND sm.product_id=p.id AND sm.state IN (\'confirmed\',\'assigned\') AND dst.location_usage IN (\'internal\',\'transit\') AND src.location_usage NOT IN (\'internal\',\'transit\')),0) AS incoming_quantity,' .
            'COALESCE((SELECT SUM(CASE WHEN sm.demand_quantity>sm.done_quantity THEN sm.demand_quantity-sm.done_quantity ELSE 0 END) ' .
            'FROM stock_moves sm JOIN stock_locations src ON src.id=sm.source_location_id JOIN stock_locations dst ON dst.id=sm.destination_location_id ' .
            'WHERE sm.company_id=p.company_id AND sm.product_id=p.id AND sm.state IN (\'confirmed\',\'assigned\') AND src.location_usage IN (\'internal\',\'transit\') AND dst.location_usage NOT IN (\'internal\',\'transit\')),0) AS outgoing_quantity ' .
            'FROM products p JOIN product_categories c ON c.id=p.category_id JOIN units_of_measure u ON u.id=p.uom_id JOIN units_of_measure pu ON pu.id=p.purchase_uom_id ' .
            'WHERE ' . $whereSql . ' ORDER BY p.name,p.id LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $list->bindValue(':limit', $limit, PDO::PARAM_INT);
        $list->bindValue(':offset', $offset, PDO::PARAM_INT);
        $list->execute();
        $records = $list->fetchAll();
        foreach ($records as &$record) {
            $record['forecast_quantity'] = (float)$record['on_hand_quantity'] + (float)$record['incoming_quantity'] - (float)$record['outgoing_quantity'];
            $record['display_price'] = $channel === 'purchase' ? $record['cost_price'] : $record['list_price'];
            $record['display_price_label'] = $channel === 'purchase' ? 'Purchase Price' : 'Sales Price';
        }
        unset($record);
        return ['records' => $records, 'categories' => $categories->fetchAll(), 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId, 'channel' => $channel];
    }

    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT p.*, c.name AS category_name, u.name AS uom_name, u.abbreviation AS uom_abbreviation, ' .
            'u.category_id AS uom_category_id, pu.name AS purchase_uom_name, pu.abbreviation AS purchase_uom_abbreviation ' .
            'FROM products p JOIN product_categories c ON c.id = p.category_id ' .
            'JOIN units_of_measure u ON u.id = p.uom_id JOIN units_of_measure pu ON pu.id = p.purchase_uom_id ' .
            'WHERE p.id = :id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $product = $query->fetch();
        if (!$product || !$authorization->canAccessCompany((int)$product['company_id'])) {
            return null;
        }
        if (!$authorization->allowsForCompany('products.create', (int)$product['company_id'])
            && !$authorization->allowsForCompany('inventory.read', (int)$product['company_id'])
            && !$authorization->allowsForCompany('inventory.write', (int)$product['company_id'])) {
            throw new AccessDeniedException('You do not have Products access for this company.');
        }
        return $product;
    }

    public function save(?int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), self::PRODUCT_FIELDS);
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported product fields: ' . implode(', ', $unknown));
        }
        $isNew = $id === null;
        $existing = $id === null ? null : $this->find($id, $user, $authorization);
        if ($id !== null && !$existing) {
            throw new InvalidArgumentException('Product was not found or is outside the allowed companies.');
        }
        $companyId = $id === null
            ? $this->positiveId($input['company_id'] ?? $user->defaultCompanyId, 'Company')
            : (int)$existing['company_id'];
        if (!$authorization->canAccessCompany($companyId)) {
            throw new InvalidArgumentException('The selected company is not available to this user.');
        }
        $requiredPermission = $isNew ? 'products.create' : 'inventory.write';
        if (!$authorization->allowsForCompany($requiredPermission, $companyId)) {
            throw new AccessDeniedException($isNew ? 'You do not have Products create access for this company.' : 'You do not have Inventory write access for this company.');
        }
        if (isset($input['company_id']) && $this->positiveId($input['company_id'], 'Company') !== $companyId) {
            throw new InvalidArgumentException('A product cannot be moved to another company after creation.');
        }

        $values = [];
        foreach (['name', 'internal_reference', 'barcode', 'product_type', 'product_class', 'carton_size', 'tracking', 'invoice_policy', 'description'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = trim((string)$input[$field]);
                $values[$field] = $value === '' ? null : $value;
            }
        }
        if ($id === null && empty($values['name'])) {
            throw new InvalidArgumentException('Product name is required.');
        }
        if (isset($values['name']) && ($values['name'] === null || mb_strlen($values['name']) > 190)) {
            throw new InvalidArgumentException('Product name is required and must be 190 characters or fewer.');
        }
        foreach (['internal_reference' => 100, 'barcode' => 100, 'description' => 65535] as $field => $maxLength) {
            if (isset($values[$field]) && mb_strlen($values[$field]) > $maxLength) {
                throw new InvalidArgumentException('Product ' . str_replace('_', ' ', $field) . ' exceeds its maximum length.');
            }
        }
        if (isset($values['carton_size']) && mb_strlen($values['carton_size']) > 64) {
            throw new InvalidArgumentException('Carton Size cannot exceed 64 characters.');
        }
        $values['product_type'] = $values['product_type'] ?? (string)($existing['product_type'] ?? 'goods');
        if (!in_array($values['product_type'], ['goods', 'service', 'combo'], true)) {
            throw new InvalidArgumentException('Product type must be Goods, Service, or Combo.');
        }
        $values['product_class'] = $values['product_class'] ?? (string)($existing['product_class'] ?? 'unclassified');
        if (!in_array($values['product_class'], ['cp', 'non_cp', 'unclassified'], true)) {
            throw new InvalidArgumentException('Product Class must be CP, Non-CP, or Unclassified.');
        }
        $values['tracking'] = $values['tracking'] ?? (string)($existing['tracking'] ?? 'none');
        if (!in_array($values['tracking'], ['none', 'lot', 'serial'], true)) {
            throw new InvalidArgumentException('Tracking must be By Quantity, By Lots, or By Unique Serial Number.');
        }
        if ($values['product_type'] === 'service' && $values['tracking'] !== 'none') {
            throw new InvalidArgumentException('Service products cannot use lot or serial tracking.');
        }
        $values['invoice_policy'] = $values['invoice_policy'] ?? (string)($existing['invoice_policy'] ?? 'ordered');
        if (!in_array($values['invoice_policy'], ['ordered', 'delivered'], true)) {
            throw new InvalidArgumentException('Invoice Policy must be Ordered quantities or Delivered quantities.');
        }
        foreach (['sale_ok', 'purchase_ok', 'active'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $this->boolean($input[$field], $field);
            }
        }
        foreach (['list_price', 'carton_price', 'cost_price', 'weight', 'volume'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $field === 'carton_price' && trim((string)$input[$field]) === ''
                    ? null
                    : $this->decimal($input[$field], $field);
            }
        }
        if (array_key_exists('units_per_carton', $input)) {
            $values['units_per_carton'] = $this->positiveNullableCount($input['units_per_carton'], 'Units per Carton');
        }
        foreach (['category_id', 'uom_id', 'purchase_uom_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $this->positiveId($input[$field], str_replace('_', ' ', $field));
            }
        }
        if (array_key_exists('income_account_id', $input)) {
            $values['income_account_id'] = $this->nullablePositiveId($input['income_account_id'], 'Income Account');
        }
        if (array_key_exists('purchase_account_id', $input)) {
            $values['purchase_account_id'] = $this->nullablePositiveId($input['purchase_account_id'], 'Purchase Expense Account');
        }
        if (!$authorization->allowsForCompany('accounting.read', $companyId)
            && (!empty($values['income_account_id']) || !empty($values['purchase_account_id']))) {
            throw new AccessDeniedException('Accounting access is required to assign product accounts.');
        }
        if ($id === null) {
            foreach (['category_id', 'uom_id', 'purchase_uom_id'] as $required) {
                if (!isset($values[$required])) {
                    throw new InvalidArgumentException('Product ' . str_replace('_', ' ', $required) . ' is required.');
                }
            }
        }
        $values['category_id'] = $values['category_id'] ?? (int)$existing['category_id'];
        $values['uom_id'] = $values['uom_id'] ?? (int)$existing['uom_id'];
        $values['purchase_uom_id'] = $values['purchase_uom_id'] ?? (int)$existing['purchase_uom_id'];
        $this->validateMasterReferences($companyId, $values['category_id'], $values['uom_id'], $values['purchase_uom_id']);
        $values['income_account_id'] = $values['income_account_id'] ?? ($existing['income_account_id'] ?? null);
        if ($values['income_account_id'] !== null) {
            $account = $this->pdo->prepare("SELECT 1 FROM accounting_accounts WHERE id=:id AND company_id=:company_id AND active=1 AND account_type LIKE 'income%' LIMIT 1");
            $account->execute(['id' => $values['income_account_id'], 'company_id' => $companyId]);
            if (!$account->fetchColumn()) throw new InvalidArgumentException('Income Account must be an active income account in this company.');
        }
        $values['purchase_account_id'] = $values['purchase_account_id'] ?? ($existing['purchase_account_id'] ?? null);
        if ($values['purchase_account_id'] !== null) {
            $allowedTypes = $values['product_type'] === 'goods'
                ? ['asset_current', 'expense', 'expense_other', 'expense_direct_cost']
                : ['expense', 'expense_other', 'expense_direct_cost'];
            $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
            $account = $this->pdo->prepare('SELECT 1 FROM accounting_accounts WHERE id=? AND company_id=? AND active=1 AND account_type IN (' . $placeholders . ') LIMIT 1');
            $account->execute(array_merge([$values['purchase_account_id'], $companyId], $allowedTypes));
            if (!$account->fetchColumn()) throw new InvalidArgumentException('Purchase Expense Account must be active in this company. Periodic goods bills and service bills use an expense account; perpetual goods use their category/company Stock Valuation Account.');
        }

        $before = $existing ? $this->auditValues($existing) : null;
        $writeValues = $values + [
            'name' => $existing['name'] ?? null,
            'internal_reference' => $existing['internal_reference'] ?? null,
            'barcode' => $existing['barcode'] ?? null,
            'product_class' => $existing['product_class'] ?? 'unclassified',
            'carton_size' => $existing['carton_size'] ?? null,
            'units_per_carton' => $existing['units_per_carton'] ?? null,
            'carton_price' => $existing['carton_price'] ?? null,
            'list_price' => $existing['list_price'] ?? '0',
            'income_account_id' => $existing['income_account_id'] ?? null,
            'purchase_account_id' => $existing['purchase_account_id'] ?? null,
            'cost_price' => $existing['cost_price'] ?? '0',
            'weight' => $existing['weight'] ?? '0',
            'volume' => $existing['volume'] ?? '0',
            'sale_ok' => $existing['sale_ok'] ?? 1,
            'purchase_ok' => $existing['purchase_ok'] ?? 1,
            'active' => $existing['active'] ?? 1,
            'description' => $existing['description'] ?? null,
            'invoice_policy' => $existing['invoice_policy'] ?? 'ordered',
        ];
        $writeValues['company_id'] = $companyId;
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            if ($existing) $this->assertValuationEditAllowed($companyId, (int)$id, $values);
            if ($id === null) {
                $writeValues['created_by'] = $actorId;
                $columns = array_keys($writeValues);
                $insert = $this->pdo->prepare(
                    'INSERT INTO products (' . implode(', ', $columns) . ') VALUES (' .
                    implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
                );
                $insert->execute($writeValues);
                $id = (int)$this->pdo->lastInsertId();
            } else {
                $columns = array_keys($writeValues);
                $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, $columns);
                $writeValues['id'] = $id;
                $this->pdo->prepare('UPDATE products SET ' . implode(', ', $assignments) . ' WHERE id = :id')->execute($writeValues);
            }
            $after = $this->find((int)$id, $user, $authorization);
            $this->audit($companyId, $actorId, $id, $isNew ? 'inventory.product.created' : 'inventory.product.updated', $before, $after, $remoteAddress);
            if ($ownsTransaction) $this->pdo->commit();
            return $after ?? throw new \RuntimeException('Saved product could not be reloaded.');
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @param list<array{row_number:int,fields:array<string,string>}> $rows @return array{created:int,updated:int} */
    public function importProducts(array $rows, int $companyId, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        if (!$authorization->canAccessCompany($companyId)) throw new AccessDeniedException('The selected company is not available to this user.');
        if (!$authorization->allowsForCompany('products.create', $companyId) && !$authorization->allowsForCompany('inventory.write', $companyId)) {
            throw new AccessDeniedException('Product create or Inventory write access is required to import products.');
        }

        $created = 0;
        $updated = 0;
        $seenIds = [];
        $seenReferences = [];
        $accountingAccess = $authorization->allowsForCompany('accounting.read', $companyId);
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $lineNumber = (int)($row['row_number'] ?? 0);
                try {
                    $raw = $row['fields'] ?? [];
                    $reference = trim((string)($raw['internal_reference'] ?? ''));
                    $recordId = null;
                    if (trim((string)($raw['record_id'] ?? '')) !== '') {
                        $recordId = $this->positiveId($raw['record_id'], 'Record ID');
                    }

                    $byId = null;
                    if ($recordId !== null) {
                        $findById = $this->pdo->prepare('SELECT id,internal_reference,carton_size,units_per_carton FROM products WHERE id=:id AND company_id=:company FOR UPDATE');
                        $findById->execute(['id' => $recordId, 'company' => $companyId]);
                        $byId = $findById->fetch() ?: null;
                        if (!$byId) throw new InvalidArgumentException('Record ID does not belong to the selected company.');
                    }
                    $byReference = null;
                    if ($reference !== '') {
                        $findByReference = $this->pdo->prepare('SELECT id,internal_reference,carton_size,units_per_carton FROM products WHERE company_id=:company AND internal_reference=:reference LIMIT 1 FOR UPDATE');
                        $findByReference->execute(['company' => $companyId, 'reference' => $reference]);
                        $byReference = $findByReference->fetch() ?: null;
                    }
                    if ($byId && $byReference && (int)$byId['id'] !== (int)$byReference['id']) {
                        throw new InvalidArgumentException('Record ID and Internal Reference point to different products.');
                    }
                    $existing = $byId ?: $byReference;
                    $productId = $existing ? (int)$existing['id'] : null;
                    if ($productId !== null && isset($seenIds[$productId])) throw new InvalidArgumentException('The same product appears more than once in this file.');
                    if ($productId !== null) $seenIds[$productId] = true;
                    if ($reference !== '') {
                        $referenceKey = mb_strtolower($reference);
                        if (isset($seenReferences[$referenceKey]) && ($productId === null || $seenReferences[$referenceKey] !== $productId)) {
                            throw new InvalidArgumentException('The same Internal Reference appears more than once in this file.');
                        }
                        $seenReferences[$referenceKey] = $productId ?? -1;
                    }

                    $input = ['company_id' => $companyId];
                    $plainFields = [
                        'name' => 'name', 'internal_reference' => 'internal_reference', 'barcode' => 'barcode',
                        'product_class' => 'product_class', 'carton_size' => 'carton_size',
                        'product_type' => 'product_type', 'tracking' => 'tracking', 'invoice_policy' => 'invoice_policy',
                        'piece_price' => 'list_price', 'carton_price' => 'carton_price', 'cost_price' => 'cost_price', 'weight' => 'weight', 'volume' => 'volume',
                        'description' => 'description',
                    ];
                    foreach ($plainFields as $source => $target) {
                        $value = trim((string)($raw[$source] ?? ''));
                        if ($value === '') continue;
                        if (in_array($source, ['product_type', 'product_class', 'tracking', 'invoice_policy'], true)) $value = $this->normalizeImportChoice($source, $value);
                        if (in_array($source, ['piece_price', 'carton_price', 'cost_price', 'weight', 'volume'], true)) $value = $this->decimal($value, $source);
                        $input[$target] = $value;
                    }
                    $unitsPerCarton = trim((string)($raw['units_per_carton'] ?? ''));
                    if ($unitsPerCarton !== '') {
                        $input['units_per_carton'] = $this->positiveNullableCount($unitsPerCarton, 'Units per Carton');
                    }
                    if (!array_key_exists('list_price', $input) && array_key_exists('carton_price', $input)) {
                        $unitCount = $input['units_per_carton'] ?? $existing['units_per_carton'] ?? null;
                        if ($unitCount === null) $unitCount = $this->cartonUnitsFromSize((string)($raw['carton_size'] ?? $existing['carton_size'] ?? ''));
                        if ($unitCount !== null && (int)$unitCount > 0) {
                            $input['list_price'] = $this->decimal(number_format((float)$input['carton_price'] / (int)$unitCount, 6, '.', ''), 'Piece Price');
                        }
                    }
                    foreach (['category' => 'category_id', 'sales_unit' => 'uom_id', 'purchase_unit' => 'purchase_uom_id'] as $source => $target) {
                        $value = trim((string)($raw[$source] ?? ''));
                        if ($value === '') continue;
                        $input[$target] = $source === 'category'
                            ? $this->importCategoryId($companyId, $value)
                            : $this->importUnitId($companyId, $value);
                    }
                    foreach (['sale_ok' => 'sale_ok', 'purchase_ok' => 'purchase_ok', 'active' => 'active'] as $source => $target) {
                        $value = trim((string)($raw[$source] ?? ''));
                        if ($value === '') continue;
                        $input[$target] = $this->importBoolean($value, $source);
                    }
                    foreach (['income_account_code' => 'income_account_id', 'purchase_account_code' => 'purchase_account_id'] as $source => $target) {
                        $code = trim((string)($raw[$source] ?? ''));
                        if ($code === '') continue;
                        if (!$accountingAccess) throw new AccessDeniedException('Accounting read access is required to import product account codes.');
                        $accountQuery = $this->pdo->prepare('SELECT id FROM accounting_accounts WHERE company_id=:company AND code=:code AND active=1 LIMIT 1');
                        $accountQuery->execute(['company' => $companyId, 'code' => $code]);
                        $accountId = $accountQuery->fetchColumn();
                        if ($accountId === false) throw new InvalidArgumentException('Account code ' . $code . ' is not active in this company.');
                        $input[$target] = (int)$accountId;
                    }

                    $this->save($productId, $input, $user, $authorization, $actorId, $remoteAddress);
                    if ($productId === null) $created++; else $updated++;
                } catch (\Throwable $error) {
                    if ($error instanceof AccessDeniedException) throw $error;
                    if ($error instanceof \PDOException) {
                        $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
                        if (str_starts_with($sqlState, '23')) {
                            throw new InvalidArgumentException(
                                'CSV row ' . max(1, $lineNumber) . ': This row conflicts with an existing product reference or barcode. Check for duplicate values.',
                                0,
                                $error
                            );
                        }
                        throw $error;
                    }
                    throw new InvalidArgumentException('CSV row ' . max(1, $lineNumber) . ': ' . $error->getMessage(), 0, $error);
                }
            }
            $this->pdo->commit();
            return ['created' => $created, 'updated' => $updated];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function createWarehouse(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $companyId = $this->positiveId($input['company_id'] ?? $user->defaultCompanyId, 'Company');
        if (!$authorization->canAccessCompany($companyId)) {
            throw new InvalidArgumentException('The selected company is not available to this user.');
        }
        if (!$authorization->allowsForCompany('inventory.write', $companyId)) {
            throw new AccessDeniedException('You do not have Inventory write access for this company.');
        }
        $name = trim((string)($input['name'] ?? ''));
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/^[A-Z0-9_-]{2,12}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Warehouse name is required and the code must contain 2–12 letters, numbers, underscores, or hyphens.');
        }
        $partnerId = null;
        if (($input['partner_contact_id'] ?? '') !== '') {
            if (!$authorization->allowsForCompany('contacts.read', $companyId)) {
                throw new AccessDeniedException('Contact read access is required to link a Partner Contact to this warehouse.');
            }
            $partnerId = $this->positiveId($input['partner_contact_id'], 'Partner Contact');
            $partner = $this->pdo->prepare('SELECT company_id FROM contacts WHERE id = :id AND active = 1 LIMIT 1');
            $partner->execute(['id' => $partnerId]);
            $partnerCompany = $partner->fetchColumn();
            if ($partnerCompany === false || ($partnerCompany !== null && (int)$partnerCompany !== $companyId)) {
                throw new InvalidArgumentException('The selected warehouse contact is not available in this company.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $companyLock = $this->pdo->prepare('SELECT id FROM companies WHERE id = :company_id FOR UPDATE');
            $companyLock->execute(['company_id' => $companyId]);
            if (!$companyLock->fetchColumn()) {
                throw new InvalidArgumentException('The selected company no longer exists.');
            }
            $create = $this->pdo->prepare('INSERT INTO warehouses (company_id, partner_contact_id, name, code) VALUES (:company_id, :partner, :name, :code)');
            $create->execute(['company_id' => $companyId, 'partner' => $partnerId, 'name' => $name, 'code' => $code]);
            $warehouseId = (int)$this->pdo->lastInsertId();
            $root = $this->insertLocation($companyId, $warehouseId, null, $code, $code, 'view');
            foreach ([['Stock', 'internal'], ['Input', 'internal'], ['Output', 'internal']] as [$locationName, $usage]) {
                $this->insertLocation($companyId, $warehouseId, $root, $locationName, $code . '/' . $locationName, $usage);
            }
            $this->ensureVirtualLocations($companyId);
            $this->audit($companyId, $actorId, $warehouseId, 'inventory.warehouse.created', null, ['id' => $warehouseId, 'name' => $name, 'code' => $code], $remoteAddress, 'inventory.warehouse');
            $this->pdo->commit();
            $query = $this->pdo->prepare('SELECT id, company_id, partner_contact_id, name, code, active FROM warehouses WHERE id = :id');
            $query->execute(['id' => $warehouseId]);
            return $query->fetch();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function updateWarehouse(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'name', 'code', 'partner_contact_id']);
        if ($unknown) throw new InvalidArgumentException('Unsupported warehouse fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $name = trim((string)($input['name'] ?? ''));
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/^[A-Z0-9_-]{2,12}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Warehouse name is required and the code must contain 2–12 letters, numbers, underscores, or hyphens.');
        }
        $partnerProvided = array_key_exists('partner_contact_id', $input);
        $partnerId = null;
        if ($partnerProvided && ($input['partner_contact_id'] ?? '') !== '' && $input['partner_contact_id'] !== null) {
            if (!$authorization->allowsForCompany('contacts.read', $companyId)) throw new AccessDeniedException('Contact read access is required to link a Partner Contact to this warehouse.');
            $partnerId = $this->positiveId($input['partner_contact_id'], 'Partner Contact');
            $partner = $this->pdo->prepare('SELECT company_id FROM contacts WHERE id=:id AND active=1 LIMIT 1');
            $partner->execute(['id' => $partnerId]);
            $partnerCompany = $partner->fetchColumn();
            if ($partnerCompany === false || ($partnerCompany !== null && (int)$partnerCompany !== $companyId)) throw new InvalidArgumentException('The selected warehouse contact is not available in this company.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $query = $this->pdo->prepare('SELECT * FROM warehouses WHERE id=:id AND company_id=:company LIMIT 1 FOR UPDATE');
            $query->execute(['id' => $id, 'company' => $companyId]);
            $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('The warehouse was not found in the selected company.');
            if (!$partnerProvided) $partnerId = $before['partner_contact_id'] === null ? null : (int)$before['partner_contact_id'];
            $this->pdo->prepare('UPDATE warehouses SET partner_contact_id=:partner,name=:name,code=:code WHERE id=:id AND company_id=:company')
                ->execute(['partner' => $partnerId, 'name' => $name, 'code' => $code, 'id' => $id, 'company' => $companyId]);
            if ((string)$before['code'] !== $code) {
                $root = $this->pdo->prepare("SELECT id FROM stock_locations WHERE company_id=:company AND warehouse_id=:warehouse AND parent_location_id IS NULL AND location_usage='view' LIMIT 1 FOR UPDATE");
                $root->execute(['company' => $companyId, 'warehouse' => $id]);
                $rootId = $root->fetchColumn();
                if ($rootId === false) throw new InvalidArgumentException('The warehouse root location is missing. Repair its location setup before changing the short code.');
                $this->pdo->prepare('UPDATE stock_locations SET name=:code WHERE id=:id AND company_id=:company')->execute(['code' => $code, 'id' => $rootId, 'company' => $companyId]);
                $this->refreshWarehouseLocationPaths($companyId, $id);
            }
            $after = $before;
            $after['partner_contact_id'] = $partnerId;
            $after['name'] = $name;
            $after['code'] = $code;
            $this->audit($companyId, $actorId, $id, 'inventory.warehouse.updated', $before, $after, $remoteAddress, 'inventory.warehouse');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setWarehouseActive(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported warehouse status fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $active = $this->boolean($input['active'] ?? null, 'Active');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $query = $this->pdo->prepare('SELECT * FROM warehouses WHERE id=:id AND company_id=:company LIMIT 1 FOR UPDATE');
            $query->execute(['id' => $id, 'company' => $companyId]);
            $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('The warehouse was not found in the selected company.');
            if ((int)$before['active'] === $active) { $this->pdo->commit(); return $before; }
            if ($active) {
                $root = $this->pdo->prepare("SELECT id FROM stock_locations WHERE company_id=:company AND warehouse_id=:warehouse AND parent_location_id IS NULL AND location_usage='view' AND active=1 LIMIT 1");
                $root->execute(['company' => $companyId, 'warehouse' => $id]);
                if ($root->fetchColumn() === false) throw new InvalidArgumentException('Restore or repair the warehouse root location before restoring this warehouse.');
            } else {
                $locations = $this->pdo->prepare("SELECT 1 FROM stock_locations WHERE company_id=:company AND warehouse_id=:warehouse AND NOT (parent_location_id IS NULL AND location_usage='view') AND active=1 LIMIT 1");
                $locations->execute(['company' => $companyId, 'warehouse' => $id]);
                if ($locations->fetchColumn()) throw new InvalidArgumentException('Archive the warehouse locations one by one before archiving this warehouse.');
                $stock = $this->pdo->prepare('SELECT 1 FROM stock_quants q JOIN stock_locations l ON l.id=q.location_id WHERE q.company_id=:company AND l.warehouse_id=:warehouse AND (q.quantity<>0 OR q.reserved_quantity<>0) LIMIT 1');
                $stock->execute(['company' => $companyId, 'warehouse' => $id]);
                if ($stock->fetchColumn()) throw new InvalidArgumentException('Move or release all stock and reservations from this warehouse before archiving it.');
                $moves = $this->pdo->prepare("SELECT 1 FROM stock_moves m JOIN stock_locations l ON l.id IN (m.source_location_id,m.destination_location_id) WHERE m.company_id=:company AND l.warehouse_id=:warehouse AND m.state IN ('draft','confirmed') LIMIT 1");
                $moves->execute(['company' => $companyId, 'warehouse' => $id]);
                if ($moves->fetchColumn()) throw new InvalidArgumentException('Complete or cancel pending stock moves for this warehouse before archiving it.');
                $counts = $this->pdo->prepare("SELECT 1 FROM inventory_cycle_counts c JOIN stock_locations l ON l.id=c.location_id WHERE c.company_id=:company AND l.warehouse_id=:warehouse AND c.state='counting' LIMIT 1");
                $counts->execute(['company' => $companyId, 'warehouse' => $id]);
                if ($counts->fetchColumn()) throw new InvalidArgumentException('Complete or cancel active cycle counts for this warehouse before archiving it.');
                $orders = $this->pdo->prepare("SELECT 1 FROM manufacturing_orders m JOIN stock_locations l ON l.id IN (m.source_location_id,m.production_location_id,m.destination_location_id) WHERE m.company_id=:company AND l.warehouse_id=:warehouse AND m.state NOT IN ('done','cancelled') LIMIT 1");
                $orders->execute(['company' => $companyId, 'warehouse' => $id]);
                if ($orders->fetchColumn()) throw new InvalidArgumentException('Complete or cancel active Manufacturing Orders for this warehouse before archiving it.');
            }
            $this->pdo->prepare('UPDATE warehouses SET active=:active WHERE id=:id AND company_id=:company')->execute(['active' => $active, 'id' => $id, 'company' => $companyId]);
            $after = $before; $after['active'] = $active;
            $this->audit($companyId, $actorId, $id, $active ? 'inventory.warehouse.restored' : 'inventory.warehouse.archived', $before, $after, $remoteAddress, 'inventory.warehouse');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function createLocation(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'warehouse_id', 'parent_location_id', 'name', 'location_usage', 'barcode', 'inventory_frequency_days']);
        if ($unknown) throw new InvalidArgumentException('Unsupported location fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $warehouseId = $this->positiveId($input['warehouse_id'] ?? null, 'Warehouse');
        $parentId = $this->positiveId($input['parent_location_id'] ?? null, 'Parent Location');
        $name = trim((string)($input['name'] ?? ''));
        $usage = (string)($input['location_usage'] ?? 'internal');
        $barcode = trim((string)($input['barcode'] ?? ''));
        $barcode = $barcode === '' ? null : $barcode;
        $frequency = filter_var($input['inventory_frequency_days'] ?? 0, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Location name is required and cannot exceed 120 characters.');
        if (!in_array($usage, ['internal', 'view'], true)) throw new InvalidArgumentException('Custom locations can use only Internal or View types; company-wide virtual types are system-managed.');
        if ($barcode !== null && mb_strlen($barcode) > 100) throw new InvalidArgumentException('Barcode cannot exceed 100 characters.');
        if ($frequency === false || $frequency < 0 || $frequency > 3650 || ($usage === 'view' && $frequency !== 0)) throw new InvalidArgumentException('Inventory frequency must be 0–3,650 days and is available only on Internal locations.');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $warehouse = $this->pdo->prepare('SELECT id FROM warehouses WHERE id=:id AND company_id=:company AND active=1 FOR UPDATE');
            $warehouse->execute(['id' => $warehouseId, 'company' => $companyId]);
            if ($warehouse->fetchColumn() === false) throw new InvalidArgumentException('Choose an active warehouse in this company.');
            $parent = $this->lockWarehouseLocation($parentId, $companyId, $warehouseId, true);
            if (!in_array($parent['location_usage'], ['view', 'internal'], true)) throw new InvalidArgumentException('Choose a View or Internal parent location.');
            if ($usage === 'view' && $parent['location_usage'] !== 'view') throw new InvalidArgumentException('A View location must be placed under another View location.');
            $completeName = $parent['complete_name'] . '/' . $name;
            $nextDate = $frequency > 0 ? (new \DateTimeImmutable($this->companyToday($companyId)))->modify('+' . $frequency . ' days')->format('Y-m-d') : null;
            $insert = $this->pdo->prepare('INSERT INTO stock_locations (company_id,warehouse_id,parent_location_id,name,complete_name,location_usage,barcode,inventory_frequency_days,next_inventory_date) VALUES (:company,:warehouse,:parent,:name,:complete,:usage,:barcode,:frequency,:next_date)');
            $insert->execute(['company' => $companyId, 'warehouse' => $warehouseId, 'parent' => $parentId, 'name' => $name, 'complete' => $completeName, 'usage' => $usage, 'barcode' => $barcode, 'frequency' => $frequency, 'next_date' => $nextDate]);
            $id = (int)$this->pdo->lastInsertId();
            $after = ['id' => $id, 'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'parent_location_id' => $parentId, 'name' => $name, 'complete_name' => $completeName, 'location_usage' => $usage, 'barcode' => $barcode, 'inventory_frequency_days' => $frequency, 'last_inventory_date' => null, 'next_inventory_date' => $nextDate, 'active' => 1];
            $this->audit($companyId, $actorId, $id, 'inventory.location.created', null, $after, $remoteAddress, 'inventory.location');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function updateLocation(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'parent_location_id', 'name', 'barcode', 'inventory_frequency_days']);
        if ($unknown) throw new InvalidArgumentException('Unsupported location fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $parentId = $this->positiveId($input['parent_location_id'] ?? null, 'Parent Location');
        $name = trim((string)($input['name'] ?? ''));
        $barcode = trim((string)($input['barcode'] ?? ''));
        $barcode = $barcode === '' ? null : $barcode;
        $frequency = filter_var($input['inventory_frequency_days'] ?? 0, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Location name is required and cannot exceed 120 characters.');
        if ($barcode !== null && mb_strlen($barcode) > 100) throw new InvalidArgumentException('Barcode cannot exceed 100 characters.');
        if ($frequency === false || $frequency < 0 || $frequency > 3650) throw new InvalidArgumentException('Inventory frequency must be 0–3,650 days.');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockWarehouseLocation($id, $companyId, null, false);
            $warehouseId = (int)$before['warehouse_id'];
            if ($before['parent_location_id'] === null || $before['location_usage'] === 'view' && $this->isWarehouseRoot($id, $companyId, $warehouseId)) {
                throw new InvalidArgumentException('Warehouse roots and company-wide system locations are managed by the warehouse setup.');
            }
            if ($before['location_usage'] !== 'internal' && $before['location_usage'] !== 'view') throw new InvalidArgumentException('Company-wide virtual locations are system-managed.');
            if ($before['location_usage'] === 'view' && $frequency !== 0) throw new InvalidArgumentException('Inventory cycle counts can be scheduled only for Internal locations.');
            $warehouse = $this->pdo->prepare('SELECT active FROM warehouses WHERE id=:id AND company_id=:company');
            $warehouse->execute(['id' => $warehouseId, 'company' => $companyId]);
            if (!(int)$warehouse->fetchColumn()) throw new InvalidArgumentException('Restore this location’s warehouse before editing it.');
            $parent = $this->lockWarehouseLocation($parentId, $companyId, $warehouseId, true);
            if (!in_array($parent['location_usage'], ['view', 'internal'], true)) throw new InvalidArgumentException('Choose a View or Internal parent location.');
            if ($before['location_usage'] === 'view' && $parent['location_usage'] !== 'view') throw new InvalidArgumentException('A View location must be placed under another View location.');
            $subtree = $this->warehouseLocationSubtree($id, $companyId, $warehouseId);
            if (in_array($parentId, $subtree, true)) throw new InvalidArgumentException('A location cannot be its own parent or be moved under one of its descendants.');
            if ((int)$before['parent_location_id'] !== $parentId) {
                foreach ($subtree as $locationId) {
                    if ($this->hasOperationalLocationReferences($locationId)) throw new InvalidArgumentException('A location hierarchy cannot be changed after the location or one of its children has been used by stock, orders, or counts. Create a new location and transfer the stock instead.');
                }
            }
            $nextDate = $frequency > 0
                ? (new \DateTimeImmutable((string)($before['last_inventory_date'] ?? $this->companyToday($companyId))))->modify('+' . $frequency . ' days')->format('Y-m-d')
                : null;
            $this->pdo->prepare('UPDATE stock_locations SET parent_location_id=:parent,name=:name,barcode=:barcode,inventory_frequency_days=:frequency,next_inventory_date=:next_date WHERE id=:id AND company_id=:company')
                ->execute(['parent' => $parentId, 'name' => $name, 'barcode' => $barcode, 'frequency' => $frequency, 'next_date' => $nextDate, 'id' => $id, 'company' => $companyId]);
            $this->refreshWarehouseLocationPaths($companyId, $warehouseId);
            $after = $before;
            $after['parent_location_id'] = $parentId; $after['name'] = $name; $after['barcode'] = $barcode; $after['inventory_frequency_days'] = $frequency; $after['next_inventory_date'] = $nextDate;
            $pathQuery = $this->pdo->prepare('SELECT complete_name FROM stock_locations WHERE id=:id AND company_id=:company');
            $pathQuery->execute(['id' => $id, 'company' => $companyId]);
            $after['complete_name'] = $pathQuery->fetchColumn();
            $this->audit($companyId, $actorId, $id, 'inventory.location.updated', $before, $after, $remoteAddress, 'inventory.location');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setLocationActive(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id', 'active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported location status fields: ' . implode(', ', $unknown));
        $companyId = $this->masterCompanyId($input, $user, $authorization);
        $active = $this->boolean($input['active'] ?? null, 'Active');
        $this->pdo->beginTransaction();
        try {
            $this->lockMasterCompany($companyId);
            $before = $this->lockWarehouseLocation($id, $companyId, null, false);
            if ($before['warehouse_id'] === null || $before['parent_location_id'] === null || $this->isWarehouseRoot($id, $companyId, (int)$before['warehouse_id'])) {
                throw new InvalidArgumentException('Warehouse roots and company-wide virtual locations are managed by the warehouse setup.');
            }
            if (!in_array($before['location_usage'], ['internal', 'view'], true)) throw new InvalidArgumentException('Company-wide virtual locations are system-managed.');
            if ((int)$before['active'] === $active) { $this->pdo->commit(); return $before; }
            $warehouse = $this->pdo->prepare('SELECT active FROM warehouses WHERE id=:id AND company_id=:company');
            $warehouse->execute(['id' => $before['warehouse_id'], 'company' => $companyId]);
            $warehouseActive = (int)$warehouse->fetchColumn();
            if ($active && !$warehouseActive) throw new InvalidArgumentException('Restore the warehouse before restoring its locations.');
            if ($active) {
                $parent = $this->pdo->prepare('SELECT active FROM stock_locations WHERE id=:id AND company_id=:company');
                $parent->execute(['id' => $before['parent_location_id'], 'company' => $companyId]);
                if (!(int)$parent->fetchColumn()) throw new InvalidArgumentException('Restore this location’s parent before restoring it.');
            } else {
                $children = $this->pdo->prepare('SELECT 1 FROM stock_locations WHERE parent_location_id=:id AND company_id=:company AND active=1 LIMIT 1');
                $children->execute(['id' => $id, 'company' => $companyId]);
                if ($children->fetchColumn()) throw new InvalidArgumentException('Archive active child locations before archiving this location.');
                $stock = $this->pdo->prepare('SELECT 1 FROM stock_quants WHERE company_id=:company AND location_id=:location AND (quantity<>0 OR reserved_quantity<>0) LIMIT 1');
                $stock->execute(['company' => $companyId, 'location' => $id]);
                if ($stock->fetchColumn()) throw new InvalidArgumentException('Move or release all stock and reservations from this location before archiving it.');
                $moves = $this->pdo->prepare("SELECT 1 FROM stock_moves WHERE company_id=:company AND (source_location_id=:source OR destination_location_id=:destination) AND state IN ('draft','confirmed') LIMIT 1");
                $moves->execute(['company' => $companyId, 'source' => $id, 'destination' => $id]);
                if ($moves->fetchColumn()) throw new InvalidArgumentException('Complete or cancel pending stock moves for this location before archiving it.');
                $counts = $this->pdo->prepare("SELECT 1 FROM inventory_cycle_counts WHERE company_id=:company AND location_id=:location AND state='counting' LIMIT 1");
                $counts->execute(['company' => $companyId, 'location' => $id]);
                if ($counts->fetchColumn()) throw new InvalidArgumentException('Complete or cancel active cycle counts for this location before archiving it.');
                $orders = $this->pdo->prepare("SELECT 1 FROM manufacturing_orders WHERE company_id=:company AND (source_location_id=:source OR production_location_id=:production OR destination_location_id=:destination) AND state NOT IN ('done','cancelled') LIMIT 1");
                $orders->execute(['company' => $companyId, 'source' => $id, 'production' => $id, 'destination' => $id]);
                if ($orders->fetchColumn()) throw new InvalidArgumentException('Complete or cancel active Manufacturing Orders for this location before archiving it.');
            }
            $this->pdo->prepare('UPDATE stock_locations SET active=:active WHERE id=:id AND company_id=:company')->execute(['active' => $active, 'id' => $id, 'company' => $companyId]);
            $after = $before; $after['active'] = $active;
            $this->audit($companyId, $actorId, $id, $active ? 'inventory.location.restored' : 'inventory.location.archived', $before, $after, $remoteAddress, 'inventory.location');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function lockWarehouseLocation(int $id, int $companyId, ?int $warehouseId, bool $activeOnly): array
    {
        $sql = 'SELECT * FROM stock_locations WHERE id=:id AND company_id=:company';
        $params = ['id' => $id, 'company' => $companyId];
        if ($warehouseId !== null) { $sql .= ' AND warehouse_id=:warehouse'; $params['warehouse'] = $warehouseId; }
        if ($activeOnly) $sql .= ' AND active=1';
        $sql .= ' LIMIT 1 FOR UPDATE';
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $location = $query->fetch();
        if (!$location) throw new InvalidArgumentException('The selected active location is not available in this company and warehouse.');
        return $location;
    }

    private function isWarehouseRoot(int $id, int $companyId, int $warehouseId): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM stock_locations WHERE id=:id AND company_id=:company AND warehouse_id=:warehouse AND parent_location_id IS NULL AND location_usage='view' LIMIT 1");
        $query->execute(['id' => $id, 'company' => $companyId, 'warehouse' => $warehouseId]);
        return (bool)$query->fetchColumn();
    }

    private function warehouseLocationSubtree(int $rootId, int $companyId, int $warehouseId): array
    {
        $query = $this->pdo->prepare('SELECT id,parent_location_id FROM stock_locations WHERE company_id=:company AND warehouse_id=:warehouse');
        $query->execute(['company' => $companyId, 'warehouse' => $warehouseId]);
        $children = [];
        foreach ($query->fetchAll() as $row) $children[(int)$row['parent_location_id']][] = (int)$row['id'];
        $result = [];
        $queue = [$rootId];
        while ($queue) {
            $current = array_shift($queue);
            if (in_array($current, $result, true)) throw new InvalidArgumentException('The location hierarchy contains a cycle and must be corrected first.');
            $result[] = $current;
            foreach ($children[$current] ?? [] as $child) $queue[] = $child;
        }
        return $result;
    }

    private function refreshWarehouseLocationPaths(int $companyId, int $warehouseId): void
    {
        $query = $this->pdo->prepare('SELECT id,parent_location_id,name FROM stock_locations WHERE company_id=:company AND warehouse_id=:warehouse');
        $query->execute(['company' => $companyId, 'warehouse' => $warehouseId]);
        $rows = $query->fetchAll();
        $byId = []; $children = [];
        foreach ($rows as $row) {
            $row['id'] = (int)$row['id'];
            $row['parent_location_id'] = $row['parent_location_id'] === null ? null : (int)$row['parent_location_id'];
            $byId[$row['id']] = $row;
            if ($row['parent_location_id'] !== null) $children[$row['parent_location_id']][] = $row['id'];
        }
        $roots = array_values(array_filter($byId, static fn (array $row): bool => $row['parent_location_id'] === null));
        if (count($roots) !== 1) throw new InvalidArgumentException('The warehouse must have exactly one root location before its paths can be updated.');
        $update = $this->pdo->prepare('UPDATE stock_locations SET complete_name=:path WHERE id=:id AND company_id=:company');
        $queue = [[$roots[0]['id'], (string)$roots[0]['name']]]; $seen = [];
        while ($queue) {
            [$id, $path] = array_shift($queue);
            if (isset($seen[$id])) throw new InvalidArgumentException('The location hierarchy contains a cycle and must be corrected first.');
            if (mb_strlen($path) > 500) throw new InvalidArgumentException('This warehouse code or location path exceeds the 500-character limit. Shorten the location name or move it closer to the root.');
            $seen[$id] = true;
            $update->execute(['path' => $path, 'id' => $id, 'company' => $companyId]);
            foreach ($children[$id] ?? [] as $childId) $queue[] = [$childId, $path . '/' . $byId[$childId]['name']];
        }
        if (count($seen) !== count($byId)) throw new InvalidArgumentException('Every warehouse location must belong to the warehouse root. Repair orphaned locations before saving.');
    }

    private function hasOperationalLocationReferences(int $locationId): bool
    {
        $references = $this->pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='stock_locations' AND REFERENCED_COLUMN_NAME='id' AND TABLE_NAME<>'stock_locations'");
        $references->execute();
        foreach ($references->fetchAll() as $reference) {
            $table = (string)$reference['TABLE_NAME']; $column = (string)$reference['COLUMN_NAME'];
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || !preg_match('/^[A-Za-z0-9_]+$/D', $column)) throw new \LogicException('The database returned an invalid location reference identifier.');
            $query = $this->pdo->prepare('SELECT 1 FROM `' . $table . '` WHERE `' . $column . '`=:id LIMIT 1');
            $query->execute(['id' => $locationId]);
            if ($query->fetchColumn()) return true;
        }
        return false;
    }

    private function companyToday(int $companyId): string
    {
        $query = $this->pdo->prepare('SELECT timezone FROM companies WHERE id=:company AND active=1');
        $query->execute(['company' => $companyId]);
        $timezone = (string)$query->fetchColumn();
        if ($timezone === '') $timezone = 'UTC';
        try { return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d'); }
        catch (\Throwable) { return gmdate('Y-m-d'); }
    }

    private function ensureVirtualLocations(int $companyId): void
    {
        $locations = [
            ['Vendors', 'Partners/Vendors', 'vendor'],
            ['Customers', 'Partners/Customers', 'customer'],
            ['Inventory Adjustment', 'Virtual Locations/Inventory Adjustment', 'inventory'],
            ['Production', 'Virtual Locations/Production', 'production'],
            ['Transit', 'Virtual Locations/Transit', 'transit'],
        ];
        $exists = $this->pdo->prepare(
            'SELECT id FROM stock_locations WHERE company_id=:company_id AND warehouse_id IS NULL AND name=:name AND location_usage=:usage LIMIT 1'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_locations (company_id, warehouse_id, parent_location_id, name, complete_name, location_usage) ' .
            'VALUES (:company_id, NULL, NULL, :name, :complete_name, :usage)'
        );
        foreach ($locations as [$name, $completeName, $usage]) {
            $exists->execute(['company_id' => $companyId, 'name' => $name, 'usage' => $usage]);
            if ($exists->fetchColumn() === false) {
                $insert->execute(['company_id' => $companyId, 'name' => $name, 'complete_name' => $completeName, 'usage' => $usage]);
            }
        }
    }

    private function insertLocation(int $companyId, int $warehouseId, ?int $parentId, string $name, string $completeName, string $usage): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_locations (company_id, warehouse_id, parent_location_id, name, complete_name, location_usage) ' .
            'VALUES (:company_id, :warehouse_id, :parent_id, :name, :complete_name, :usage)'
        );
        $insert->execute(['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'parent_id' => $parentId, 'name' => $name, 'complete_name' => $completeName, 'usage' => $usage]);
        return (int)$this->pdo->lastInsertId();
    }

    private function validateMasterReferences(int $companyId, int $categoryId, int $uomId, int $purchaseUomId): void
    {
        $category = $this->pdo->prepare('SELECT company_id FROM product_categories WHERE id = :id AND active = 1 LIMIT 1');
        $category->execute(['id' => $categoryId]);
        $categoryCompany = $category->fetchColumn();
        if ($categoryCompany === false || ($categoryCompany !== null && (int)$categoryCompany !== $companyId)) {
            throw new InvalidArgumentException('Select an active product category available to this company.');
        }
        $units = $this->pdo->prepare(
            'SELECT u.id, u.company_id, u.category_id, c.company_id AS category_company_id ' .
            'FROM units_of_measure u JOIN uom_categories c ON c.id = u.category_id ' .
            'WHERE u.id IN (:uom_id, :purchase_uom_id) AND u.active = 1 AND c.active = 1'
        );
        $units->execute(['uom_id' => $uomId, 'purchase_uom_id' => $purchaseUomId]);
        $rows = $units->fetchAll();
        if (count($rows) !== ($uomId === $purchaseUomId ? 1 : 2)) {
            throw new InvalidArgumentException('Select active units of measure.');
        }
        $byId = [];
        foreach ($rows as $row) {
            if (($row['company_id'] !== null && (int)$row['company_id'] !== $companyId) || ($row['category_company_id'] !== null && (int)$row['category_company_id'] !== $companyId)) {
                throw new InvalidArgumentException('Select units of measure available to this company.');
            }
            $byId[(int)$row['id']] = (int)$row['category_id'];
        }
        if (($byId[$uomId] ?? null) !== ($byId[$purchaseUomId] ?? null)) {
            throw new InvalidArgumentException('Sales and purchase units must belong to the same unit category.');
        }
    }

    public function valuation(UserContext $user, Authorization $authorization, array $filters): array
    {
        $rawCompanyId = $filters['company_id'] ?? null;
        $companyId = $rawCompanyId === null || $rawCompanyId === '' ? null : $this->positiveId($rawCompanyId, 'Company');
        $companyId = $this->resolveCompany($user, $authorization, $companyId);
        $queryText = trim((string)($filters['q'] ?? ''));
        if (mb_strlen($queryText) > 190) throw new InvalidArgumentException('Valuation search text must be 190 characters or fewer.');
        $where = ['p.company_id=:product_company'];
        $params = ['product_company' => $companyId, 'balance_company' => $companyId, 'stock_company' => $companyId, 'settings_company' => $companyId, 'override_company' => $companyId];
        if ($queryText !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queryText) . '%';
            $where[] = '(p.name LIKE :q_name ESCAPE \'!\' OR p.internal_reference LIKE :q_reference ESCAPE \'!\')';
            $params['q_name'] = $like;
            $params['q_reference'] = $like;
        }
        $stockPositions = '(SELECT q.company_id,q.product_id,SUM(q.quantity) AS quantity FROM stock_quants q JOIN stock_locations l ON l.id=q.location_id WHERE q.company_id=:stock_company AND l.location_usage IN (\'internal\',\'transit\') GROUP BY q.company_id,q.product_id) stock';
        $baseFrom = ' FROM products p LEFT JOIN product_categories c ON c.id=p.category_id ' .
            'LEFT JOIN units_of_measure u ON u.id=p.uom_id ' .
            'LEFT JOIN inventory_valuation_balances v ON v.company_id=:balance_company AND v.product_id=p.id ' .
            'LEFT JOIN inventory_accounting_settings valuation_settings ON valuation_settings.company_id=:settings_company ' .
            'LEFT JOIN product_category_accounting_overrides category_override ON category_override.company_id=:override_company AND category_override.category_id=p.category_id ' .
            'LEFT JOIN ' . $stockPositions . ' ON stock.company_id=p.company_id AND stock.product_id=p.id ';
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $baseFrom . 'WHERE ' . $whereSql . ' AND (COALESCE(v.quantity,0)<>0 OR COALESCE(v.value,0)<>0 OR COALESCE(stock.quantity,0)<>0)');
        $count->execute($params);
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $query = $this->pdo->prepare(
            'SELECT p.id,p.company_id,p.internal_reference,p.name AS product_name,c.name AS category_name,COALESCE(category_override.costing_method,valuation_settings.costing_method,c.costing_method) AS costing_method,u.abbreviation AS uom,' .
            'COALESCE(stock.quantity,0) AS physical_quantity,COALESCE(v.quantity,0) AS valuation_quantity,COALESCE(v.value,0) AS valuation_value,' .
            'CASE WHEN COALESCE(v.quantity,0)=0 THEN 0 ELSE CAST(ROUND(v.value/v.quantity,6) AS DECIMAL(20,6)) END AS unit_value,' .
            'CAST(COALESCE(stock.quantity,0)-COALESCE(v.quantity,0) AS DECIMAL(20,6)) AS quantity_discrepancy,co.currency_code,cur.decimal_places' .
            $baseFrom . 'JOIN companies co ON co.id=p.company_id JOIN currencies cur ON cur.currency_code=co.currency_code ' .
            'WHERE ' . $whereSql . ' AND (COALESCE(v.quantity,0)<>0 OR COALESCE(v.value,0)<>0 OR COALESCE(stock.quantity,0)<>0) ' .
            'ORDER BY p.name,p.id LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        return ['records' => $query->fetchAll(), 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId];
    }

    private function assertValuationEditAllowed(int $companyId, int $productId, array $values): void
    {
        $productQuery = $this->pdo->prepare(
            'SELECT p.cost_price,p.category_id,COALESCE(old_override.costing_method,old_settings.costing_method,c.costing_method) AS costing_method ' .
            'FROM products p JOIN product_categories c ON c.id=p.category_id ' .
            'LEFT JOIN inventory_accounting_settings old_settings ON old_settings.company_id=p.company_id ' .
            'LEFT JOIN product_category_accounting_overrides old_override ON old_override.company_id=p.company_id AND old_override.category_id=p.category_id ' .
            'WHERE p.id=:product AND p.company_id=:company FOR UPDATE'
        );
        $productQuery->execute(['product' => $productId, 'company' => $companyId]);
        $product = $productQuery->fetch();
        if (!$product) throw new InvalidArgumentException('Product is no longer available for valuation changes.');
        $quantityQuery = $this->pdo->prepare("SELECT COALESCE(SUM(q.quantity),0) FROM stock_quants q JOIN stock_locations l ON l.id=q.location_id WHERE q.company_id=:company AND q.product_id=:product AND l.location_usage IN ('internal','transit')");
        $quantityQuery->execute(['company' => $companyId, 'product' => $productId]);
        $hasStockQuery = $this->pdo->prepare('SELECT CAST(:quantity AS DECIMAL(20,6))>0');
        $hasStockQuery->execute(['quantity' => (string)$quantityQuery->fetchColumn()]);
        if (!(bool)$hasStockQuery->fetchColumn()) return;

        $categoryQuery = $this->pdo->prepare(
            'SELECT COALESCE(category_override.costing_method,company_settings.costing_method,c.costing_method) AS costing_method ' .
            'FROM product_categories c LEFT JOIN inventory_accounting_settings company_settings ON company_settings.company_id=:settings_company ' .
            'LEFT JOIN product_category_accounting_overrides category_override ON category_override.company_id=:override_company AND category_override.category_id=c.id ' .
            'WHERE c.id=:category AND c.active=1 AND (c.company_id IS NULL OR c.company_id=:company)'
        );
        $categoryQuery->execute(['settings_company'=>$companyId,'override_company'=>$companyId,'category' => $values['category_id'], 'company' => $companyId]);
        $newMethod = $categoryQuery->fetchColumn();
        if ($newMethod === false) throw new InvalidArgumentException('Product category is no longer available for this company.');
        if ((string)$product['costing_method'] !== (string)$newMethod) {
            throw new InvalidArgumentException('A product with stock on hand cannot change costing method; transfer or revalue the stock first.');
        }
        if (array_key_exists('cost_price', $values)) {
            $costChanged = $this->pdo->prepare('SELECT CAST(:new_cost AS DECIMAL(20,6))<>CAST(:old_cost AS DECIMAL(20,6))');
            $costChanged->execute(['new_cost' => $values['cost_price'], 'old_cost' => $product['cost_price']]);
            if ((bool)$costChanged->fetchColumn()) {
                $message = $newMethod === 'standard'
                    ? 'Changing Standard Cost while stock is on hand requires an inventory revaluation adjustment, which is not configured yet.'
                    : 'Cost is calculated from inventory valuation for FIFO and Average products while stock is on hand.';
                throw new InvalidArgumentException($message);
            }
        }
    }

    private function resolveCompany(UserContext $user, Authorization $authorization, ?int $companyId): int
    {
        $companyId ??= $user->defaultCompanyId ?? (count($user->companyIds) === 1 ? $user->companyIds[0] : null);
        if ($companyId === null || $companyId < 1) {
            throw new InvalidArgumentException('Choose an allowed company.');
        }
        if (!$authorization->canAccessCompany($companyId)) {
            throw new InvalidArgumentException('The selected company is not available to your account.');
        }
        return $companyId;
    }

    /** @return list<array{id:int,name:string}> */
    private function allowedCompanies(UserContext $user): array
    {
        if ($user->superuser) {
            return $this->pdo->query('SELECT id,name FROM companies WHERE active=1 ORDER BY name,id')->fetchAll();
        }
        if (!$user->companyIds) return [];
        $placeholders = [];
        $params = [];
        foreach ($user->companyIds as $index => $companyId) {
            $placeholders[] = ':company_' . $index;
            $params['company_' . $index] = $companyId;
        }
        $query = $this->pdo->prepare('SELECT id,name FROM companies WHERE active=1 AND id IN (' . implode(',', $placeholders) . ') ORDER BY name,id');
        $query->execute($params);
        return $query->fetchAll();
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException($label . ' must be a positive integer.');
        }
        return $id;
    }

    private function nullablePositiveId(mixed $value, string $label): ?int
    {
        return $value === null || $value === '' ? null : $this->positiveId($value, $label);
    }

    private function boolean(mixed $value, string $label): int
    {
        if (in_array($value, [true, 1, '1', 'true', 'on'], true)) return 1;
        if (in_array($value, [false, 0, '0', 'false', 'off', ''], true)) return 0;
        throw new InvalidArgumentException($label . ' must be true or false.');
    }

    private function importBoolean(string $value, string $label): int
    {
        $normalized = mb_strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'active', 'enabled'], true)) return 1;
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'inactive', 'archived', 'disabled'], true)) return 0;
        throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($label)) . ' must be Yes or No.');
    }

    private function normalizeImportChoice(string $field, string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $choices = match ($field) {
            'product_class' => [
                'cp' => 'cp',
                'non-cp' => 'non_cp', 'non cp' => 'non_cp', 'noncp' => 'non_cp',
                'unclassified' => 'unclassified', 'not specified' => 'unclassified', 'unspecified' => 'unclassified',
            ],
            'product_type' => ['goods' => 'goods', 'good' => 'goods', 'service' => 'service', 'combo' => 'combo'],
            'tracking' => [
                'none' => 'none', 'quantity' => 'none', 'by quantity' => 'none', 'untracked' => 'none',
                'lot' => 'lot', 'lots' => 'lot', 'by lots' => 'lot',
                'serial' => 'serial', 'serial number' => 'serial', 'by unique serial number' => 'serial',
            ],
            'invoice_policy' => [
                'ordered' => 'ordered', 'ordered quantity' => 'ordered', 'ordered quantities' => 'ordered',
                'delivered' => 'delivered', 'delivered quantity' => 'delivered', 'delivered quantities' => 'delivered',
            ],
            default => [],
        };
        if (!isset($choices[$normalized])) {
            $label = match ($field) {
                'product_class' => 'Product Class must be CP, Non-CP, or Unclassified.',
                'product_type' => 'Product Type must be Goods, Service, or Combo.',
                'tracking' => 'Tracking must be By Quantity, By Lots, or By Unique Serial Number.',
                default => 'Invoice Policy must be Ordered quantities or Delivered quantities.',
            };
            throw new InvalidArgumentException($label);
        }
        return $choices[$normalized];
    }

    private function importCategoryId(int $companyId, string $name): int
    {
        $query = $this->pdo->prepare('SELECT id,company_id FROM product_categories WHERE name=:name AND active=1 AND (company_id IS NULL OR company_id=:company) ORDER BY (company_id=:company_order) DESC,id');
        $query->execute(['name' => $name, 'company' => $companyId, 'company_order' => $companyId]);
        $rows = $query->fetchAll();
        if (!$rows) throw new InvalidArgumentException('Product Category "' . $name . '" is not active in this company.');
        $local = array_values(array_filter($rows, static fn (array $row): bool => $row['company_id'] !== null && (int)$row['company_id'] === $companyId));
        $matches = $local ?: $rows;
        if (count($matches) !== 1) throw new InvalidArgumentException('Product Category "' . $name . '" is ambiguous; use one unique category name.');
        return (int)$matches[0]['id'];
    }

    private function importUnitId(int $companyId, string $label): int
    {
        $name = $label;
        $abbreviation = null;
        $categoryName = null;
        if (preg_match('/^(.+?)\s+\(([^()]*)\)(?:\s+·\s+(.+))?$/u', $label, $match) === 1) {
            $name = trim($match[1]);
            $abbreviation = trim($match[2]);
            $categoryName = isset($match[3]) ? trim($match[3]) : null;
        }
        $sql = 'SELECT u.id,u.company_id,c.company_id AS category_company_id FROM units_of_measure u JOIN uom_categories c ON c.id=u.category_id ' .
            'WHERE u.name=:name AND u.active=1 AND c.active=1 AND (u.company_id IS NULL OR u.company_id=:uom_company) AND (c.company_id IS NULL OR c.company_id=:category_company)';
        $params = ['name' => $name, 'uom_company' => $companyId, 'category_company' => $companyId];
        if ($abbreviation !== null) { $sql .= ' AND u.abbreviation=:abbreviation'; $params['abbreviation'] = $abbreviation; }
        if ($categoryName !== null && $categoryName !== '') { $sql .= ' AND c.name=:category_name'; $params['category_name'] = $categoryName; }
        $query = $this->pdo->prepare($sql . ' ORDER BY u.id');
        $query->execute($params);
        $rows = $query->fetchAll();
        if (!$rows) throw new InvalidArgumentException('Unit of Measure "' . $label . '" is not active in this company.');
        $local = array_values(array_filter($rows, static fn (array $row): bool => ($row['company_id'] !== null && (int)$row['company_id'] === $companyId) || ($row['category_company_id'] !== null && (int)$row['category_company_id'] === $companyId)));
        $matches = $local ?: $rows;
        if (count($matches) !== 1) throw new InvalidArgumentException('Unit of Measure "' . $label . '" is ambiguous; use the full unit label shown in the exported file.');
        return (int)$matches[0]['id'];
    }

    private function decimal(mixed $value, string $label): string
    {
        $text = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D', $text) !== 1) {
            throw new InvalidArgumentException($label . ' must be a non-negative decimal with up to 6 fractional digits.');
        }
        return $text;
    }

    private function positiveNullableCount(mixed $value, string $label): ?int
    {
        if ($value === null || trim((string)$value) === '') return null;
        $count = filter_var($value, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 4_294_967_295) throw new InvalidArgumentException($label . ' must be a positive whole number.');
        return $count;
    }

    private function cartonUnitsFromSize(string $cartonSize): ?int
    {
        $cartonSize = trim($cartonSize);
        if ($cartonSize === '') return null;
        if (preg_match('/^(\d+)\s*(?:\*|x|×)\s*(\d+)$/iu', $cartonSize, $match) === 1) {
            $first = filter_var($match[1], FILTER_VALIDATE_INT);
            $second = filter_var($match[2], FILTER_VALIDATE_INT);
            if ($first === false || $second === false || $first < 1 || $second < 1 || $first > 4_294_967_295 || $second > intdiv(4_294_967_295, $first)) return null;
            return $first * $second;
        }
        if (preg_match('/^\d+$/D', $cartonSize) === 1) {
            $units = filter_var($cartonSize, FILTER_VALIDATE_INT);
            return $units !== false && $units > 0 && $units <= 4_294_967_295 ? $units : null;
        }
        return null;
    }

    private function auditValues(array $values): array
    {
        $fields = [...self::PRODUCT_FIELDS, 'id', 'active'];
        return array_intersect_key($values, array_flip($fields));
    }

    private function audit(int $companyId, int $actorId, int $recordId, string $event, ?array $before, ?array $after, string $remoteAddress, string $model = 'inventory.product'): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $insert = $this->pdo->prepare(
            'INSERT INTO audit_events (company_id, user_id, event_key, model_key, record_id, old_values, new_values, ip_address) ' .
            'VALUES (:company_id, :user_id, :event_key, :model_key, :record_id, :old_values, :new_values, :ip_address)'
        );
        $insert->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $insert->bindValue(':user_id', $actorId, PDO::PARAM_INT);
        $insert->bindValue(':event_key', $event);
        $insert->bindValue(':model_key', $model);
        $insert->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $insert->bindValue(':old_values', $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), $before === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':new_values', $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), $after === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':ip_address', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->execute();
    }
}
