<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class InventoryAccountingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function read(mixed $rawCompanyId, UserContext $user, Authorization $authorization): array
    {
        $companyId = $this->companyId($rawCompanyId, $user, $authorization);
        $query = $this->pdo->prepare('SELECT * FROM inventory_accounting_settings WHERE company_id=:company');
        $query->execute(['company' => $companyId]);
        $settings = $query->fetch() ?: [
            'company_id' => $companyId,
            'accounting_standard' => null,
            'valuation_method' => 'periodic',
            'closing_frequency' => 'manual',
            'costing_method' => 'fifo',
            'valuation_account_id' => null,
            'variation_account_id' => null,
            'expense_adjustment_account_id' => null,
            'journal_id' => null,
        ];
        foreach (['company_id', 'valuation_account_id', 'variation_account_id', 'expense_adjustment_account_id', 'journal_id'] as $field) {
            if ($settings[$field] !== null) $settings[$field] = (int)$settings[$field];
        }
        $settings['ready'] = $settings['accounting_standard'] !== null
            && $settings['valuation_account_id'] !== null
            && $settings['variation_account_id'] !== null
            && $settings['journal_id'] !== null;

        $accounts = $this->pdo->prepare('SELECT id,code,name,account_type,active FROM accounting_accounts WHERE company_id=:company AND active=1 ORDER BY code,id');
        $accounts->execute(['company' => $companyId]);
        $journals = $this->pdo->prepare("SELECT id,code,name,journal_type FROM accounting_journals WHERE company_id=:company AND active=1 AND journal_type='general' ORDER BY name,id");
        $journals->execute(['company' => $companyId]);
        $categories = $this->pdo->prepare(
            'SELECT c.id,c.name,c.company_id,o.costing_method AS costing_method_override,o.valuation_method AS valuation_method_override,' .
            'o.valuation_account_id,o.variation_account_id,o.expense_account_id,o.expense_adjustment_account_id,o.price_difference_account_id FROM product_categories c ' .
            'LEFT JOIN product_category_accounting_overrides o ON o.category_id=c.id AND o.company_id=:override_company ' .
            'WHERE c.active=1 AND (c.company_id IS NULL OR c.company_id=:category_company) ORDER BY c.name,c.id'
        );
        $categories->execute(['override_company' => $companyId, 'category_company' => $companyId]);
        $locations = $this->pdo->prepare(
            "SELECT id,name,complete_name,location_usage,inventory_loss_account_id,cost_of_production_account_id FROM stock_locations " .
            "WHERE company_id=:company AND active=1 AND location_usage IN ('inventory','production') ORDER BY location_usage,complete_name,id"
        );
        $locations->execute(['company' => $companyId]);
        return [
            'company_id' => $companyId,
            'settings' => $settings,
            'accounts' => $accounts->fetchAll(),
            'journals' => $journals->fetchAll(),
            'categories' => $categories->fetchAll(),
            'locations' => $locations->fetchAll(),
        ];
    }

    public function save(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','settings','categories','locations']);
        if ($unknown) throw new InvalidArgumentException('Unsupported inventory accounting fields: ' . implode(', ', $unknown));
        $companyId = $this->companyId($input['company_id'] ?? null, $user, $authorization);
        if (!is_array($input['settings'] ?? null)) throw new InvalidArgumentException('Inventory accounting settings are required.');
        $raw = $input['settings'];
        $unknown = array_diff(array_keys($raw), ['accounting_standard','valuation_method','closing_frequency','costing_method','valuation_account_id','variation_account_id','expense_adjustment_account_id','journal_id']);
        if ($unknown) throw new InvalidArgumentException('Unsupported inventory valuation settings: ' . implode(', ', $unknown));
        $standard = $this->enum($raw['accounting_standard'] ?? null, ['continental','anglo_saxon'], 'Accounting Standard', true);
        $method = $this->enum($raw['valuation_method'] ?? null, ['perpetual','periodic'], 'Inventory Valuation');
        $frequency = $this->enum($raw['closing_frequency'] ?? null, ['manual','daily','monthly'], 'Periodic Valuation Frequency');
        $costing = $this->enum($raw['costing_method'] ?? null, ['standard','fifo','average'], 'Inventory Cost Method');
        $valuationAccount = $this->nullableId($raw['valuation_account_id'] ?? null, 'Valuation Account');
        $variationAccount = $this->nullableId($raw['variation_account_id'] ?? null, 'Variation Account');
        $expenseAdjustmentAccount = $this->nullableId($raw['expense_adjustment_account_id'] ?? null, 'Expense Adjustment Account');
        $journalId = $this->nullableId($raw['journal_id'] ?? null, 'Inventory Valuation Journal');
        if ($valuationAccount !== null) $this->assertAccount($valuationAccount, $companyId, ['asset_current','asset_non_current'], 'Valuation Account');
        if ($variationAccount !== null) $this->assertAccount($variationAccount, $companyId, ['asset_current','expense','expense_other','expense_direct_cost'], 'Variation Account');
        if ($expenseAdjustmentAccount !== null) $this->assertAccount($expenseAdjustmentAccount, $companyId, ['expense','expense_other','expense_direct_cost'], 'Expense Adjustment Account');
        if ($journalId !== null) $this->assertJournal($journalId, $companyId);

        $categoryRows = $input['categories'] ?? [];
        $locationRows = $input['locations'] ?? [];
        if (!is_array($categoryRows) || !array_is_list($categoryRows)) throw new InvalidArgumentException('Category overrides must be a list.');
        if (!is_array($locationRows) || !array_is_list($locationRows)) throw new InvalidArgumentException('Location accounts must be a list.');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT company_id FROM inventory_accounting_settings WHERE company_id=:company FOR UPDATE')->execute(['company'=>$companyId]);
            $before = $this->read($companyId, $user, $authorization);
            $this->assertDefaultCostingChangeAllowed($companyId, (string)$before['settings']['costing_method'], $costing);
            $this->assertCategoryCostChangesAllowed($categoryRows, $companyId, (string)$before['settings']['costing_method'], $costing);
            $this->pdo->prepare(
                'INSERT INTO inventory_accounting_settings (company_id,accounting_standard,valuation_method,closing_frequency,costing_method,valuation_account_id,variation_account_id,expense_adjustment_account_id,journal_id,created_by,updated_by) ' .
                'VALUES (:company,:standard,:method,:frequency,:costing,:valuation,:variation,:expense_adjustment,:journal,:actor,:actor_update) ' .
                'ON DUPLICATE KEY UPDATE accounting_standard=VALUES(accounting_standard),valuation_method=VALUES(valuation_method),closing_frequency=VALUES(closing_frequency),costing_method=VALUES(costing_method),valuation_account_id=VALUES(valuation_account_id),variation_account_id=VALUES(variation_account_id),expense_adjustment_account_id=VALUES(expense_adjustment_account_id),journal_id=VALUES(journal_id),updated_by=VALUES(updated_by)'
            )->execute(['company'=>$companyId,'standard'=>$standard,'method'=>$method,'frequency'=>$frequency,'costing'=>$costing,'valuation'=>$valuationAccount,'variation'=>$variationAccount,'expense_adjustment'=>$expenseAdjustmentAccount,'journal'=>$journalId,'actor'=>$actorId,'actor_update'=>$actorId]);

            $seenCategories = [];
            foreach ($categoryRows as $index => $row) {
                if (!is_array($row)) throw new InvalidArgumentException('Category row ' . ($index + 1) . ' is invalid.');
                $unknown = array_diff(array_keys($row), ['id','costing_method','valuation_method','valuation_account_id','variation_account_id','expense_account_id','expense_adjustment_account_id','price_difference_account_id']);
                if ($unknown) throw new InvalidArgumentException('Unsupported category override fields: ' . implode(', ', $unknown));
                $categoryId = $this->positiveId($row['id'] ?? null, 'Product Category');
                if (isset($seenCategories[$categoryId])) throw new InvalidArgumentException('A product category was submitted more than once.');
                $seenCategories[$categoryId] = true;
                $this->assertCategory($categoryId, $companyId);
                $categoryCosting = $this->enum($row['costing_method'] ?? null, ['standard','fifo','average'], 'Category Cost Method', true);
                $categoryMethod = $this->enum($row['valuation_method'] ?? null, ['perpetual','periodic'], 'Category Inventory Valuation', true);
                $categoryValuationAccount = $this->nullableId($row['valuation_account_id'] ?? null, 'Category Valuation Account');
                $categoryVariationAccount = $this->nullableId($row['variation_account_id'] ?? null, 'Category Variation Account');
                $categoryExpenseAccount = $this->nullableId($row['expense_account_id'] ?? null, 'Category Expense Account');
                $categoryExpenseAdjustmentAccount = $this->nullableId($row['expense_adjustment_account_id'] ?? null, 'Category Expense Adjustment Account');
                $categoryPriceDifferenceAccount = $this->nullableId($row['price_difference_account_id'] ?? null, 'Category Price Difference Account');
                if ($categoryValuationAccount !== null) $this->assertAccount($categoryValuationAccount, $companyId, ['asset_current','asset_non_current'], 'Category Valuation Account');
                if ($categoryVariationAccount !== null) $this->assertAccount($categoryVariationAccount, $companyId, ['asset_current','expense','expense_other','expense_direct_cost'], 'Category Variation Account');
                if ($categoryExpenseAccount !== null) $this->assertAccount($categoryExpenseAccount, $companyId, ['expense','expense_other','expense_direct_cost'], 'Category Expense Account');
                if ($categoryExpenseAdjustmentAccount !== null) $this->assertAccount($categoryExpenseAdjustmentAccount, $companyId, ['expense','expense_other','expense_direct_cost'], 'Category Expense Adjustment Account');
                if ($categoryPriceDifferenceAccount !== null) $this->assertAccount($categoryPriceDifferenceAccount, $companyId, ['expense','expense_other','expense_direct_cost'], 'Category Price Difference Account');
                if ($categoryCosting === null && $categoryMethod === null && $categoryValuationAccount === null && $categoryVariationAccount === null && $categoryExpenseAccount === null && $categoryExpenseAdjustmentAccount === null && $categoryPriceDifferenceAccount === null) {
                    $this->pdo->prepare('DELETE FROM product_category_accounting_overrides WHERE company_id=:company AND category_id=:category')->execute(['company'=>$companyId,'category'=>$categoryId]);
                } else {
                    $this->pdo->prepare(
                        'INSERT INTO product_category_accounting_overrides (company_id,category_id,costing_method,valuation_method,valuation_account_id,variation_account_id,expense_account_id,expense_adjustment_account_id,price_difference_account_id,updated_by) ' .
                        'VALUES (:company,:category,:costing,:method,:valuation,:variation,:expense,:expense_adjustment,:price_difference,:actor) ON DUPLICATE KEY UPDATE costing_method=VALUES(costing_method),valuation_method=VALUES(valuation_method),valuation_account_id=VALUES(valuation_account_id),variation_account_id=VALUES(variation_account_id),expense_account_id=VALUES(expense_account_id),expense_adjustment_account_id=VALUES(expense_adjustment_account_id),price_difference_account_id=VALUES(price_difference_account_id),updated_by=VALUES(updated_by)'
                    )->execute(['company'=>$companyId,'category'=>$categoryId,'costing'=>$categoryCosting,'method'=>$categoryMethod,'valuation'=>$categoryValuationAccount,'variation'=>$categoryVariationAccount,'expense'=>$categoryExpenseAccount,'expense_adjustment'=>$categoryExpenseAdjustmentAccount,'price_difference'=>$categoryPriceDifferenceAccount,'actor'=>$actorId]);
                }
            }

            $seenLocations = [];
            foreach ($locationRows as $index => $row) {
                if (!is_array($row)) throw new InvalidArgumentException('Location row ' . ($index + 1) . ' is invalid.');
                $unknown = array_diff(array_keys($row), ['id','inventory_loss_account_id','cost_of_production_account_id']);
                if ($unknown) throw new InvalidArgumentException('Unsupported location accounting fields: ' . implode(', ', $unknown));
                $locationId = $this->positiveId($row['id'] ?? null, 'Stock Location');
                if (isset($seenLocations[$locationId])) throw new InvalidArgumentException('A stock location was submitted more than once.');
                $seenLocations[$locationId] = true;
                $location = $this->location($locationId, $companyId);
                $lossId = $this->nullableId($row['inventory_loss_account_id'] ?? null, 'Inventory Loss Account');
                $productionId = $this->nullableId($row['cost_of_production_account_id'] ?? null, 'Cost of Production Account');
                if ($location['location_usage'] !== 'inventory' && $lossId !== null) throw new InvalidArgumentException('Inventory Loss Account can only be set on an Inventory Loss location.');
                if ($location['location_usage'] !== 'production' && $productionId !== null) throw new InvalidArgumentException('Cost of Production Account can only be set on a Production location.');
                if ($lossId !== null) $this->assertAccount($lossId, $companyId, ['expense','expense_other','expense_direct_cost'], 'Inventory Loss Account');
                if ($productionId !== null) $this->assertAccount($productionId, $companyId, ['asset_current','expense','expense_direct_cost'], 'Cost of Production Account');
                $this->pdo->prepare('UPDATE stock_locations SET inventory_loss_account_id=:loss,cost_of_production_account_id=:production WHERE id=:id AND company_id=:company')
                    ->execute(['loss'=>$lossId,'production'=>$productionId,'id'=>$locationId,'company'=>$companyId]);
            }

            $this->audit($companyId, $actorId, 'inventory.accounting_settings.updated', 'inventory_accounting_settings', $before, [
                'settings' => ['accounting_standard'=>$standard,'valuation_method'=>$method,'closing_frequency'=>$frequency,'costing_method'=>$costing,'valuation_account_id'=>$valuationAccount,'variation_account_id'=>$variationAccount,'expense_adjustment_account_id'=>$expenseAdjustmentAccount,'journal_id'=>$journalId],
                'category_override_count' => count($seenCategories),
                'location_account_count' => count($seenLocations),
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->read($companyId, $user, $authorization);
    }

    private function companyId(mixed $value, UserContext $user, Authorization $authorization): int
    {
        $id = $value === null || $value === '' ? $user->defaultCompanyId : $this->positiveId($value, 'Company');
        if ($id < 1 || !$authorization->canAccessCompany($id)) throw new InvalidArgumentException('The selected company is not available to your account.');
        return $id;
    }

    private function assertAccount(int $id, int $companyId, array $types, string $label): void
    {
        $query = $this->pdo->prepare('SELECT account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');
        $query->execute(['id'=>$id,'company'=>$companyId]);
        $type = $query->fetchColumn();
        if ($type === false || !in_array($type, $types, true)) throw new InvalidArgumentException($label . ' must be an active account of the correct type in this company.');
    }

    private function assertJournal(int $id, int $companyId): void
    {
        $query = $this->pdo->prepare("SELECT 1 FROM accounting_journals WHERE id=:id AND company_id=:company AND active=1 AND journal_type='general'");
        $query->execute(['id'=>$id,'company'=>$companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Inventory Valuation Journal must be an active General Journal in this company.');
    }

    private function assertCategory(int $id, int $companyId): void
    {
        $query = $this->pdo->prepare('SELECT 1 FROM product_categories WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company)');
        $query->execute(['id'=>$id,'company'=>$companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an active product category available to this company.');
    }

    private function location(int $id, int $companyId): array
    {
        $query = $this->pdo->prepare("SELECT id,location_usage FROM stock_locations WHERE id=:id AND company_id=:company AND active=1 AND location_usage IN ('inventory','production')");
        $query->execute(['id'=>$id,'company'=>$companyId]);
        $row = $query->fetch();
        if (!$row) throw new InvalidArgumentException('Select an active Inventory Loss or Production location from this company.');
        return $row;
    }

    private function assertDefaultCostingChangeAllowed(int $companyId, string $oldMethod, string $newMethod): void
    {
        if ($oldMethod === $newMethod) return;
        $locks = $this->pdo->prepare(
            'SELECT p.id FROM products p LEFT JOIN product_category_accounting_overrides o ON o.company_id=p.company_id AND o.category_id=p.category_id ' .
            'WHERE p.company_id=:company AND o.costing_method IS NULL ORDER BY p.id FOR UPDATE'
        );
        $locks->execute(['company'=>$companyId]);
        $locks->fetchAll(PDO::FETCH_COLUMN);
        $query = $this->pdo->prepare(
            "SELECT EXISTS(SELECT 1 FROM products p JOIN product_categories c ON c.id=p.category_id " .
            "LEFT JOIN product_category_accounting_overrides o ON o.company_id=p.company_id AND o.category_id=p.category_id " .
            "JOIN stock_quants q ON q.company_id=p.company_id AND q.product_id=p.id AND q.quantity>0 " .
            "JOIN stock_locations l ON l.id=q.location_id AND l.location_usage IN ('internal','transit') " .
            "WHERE p.company_id=:company AND o.costing_method IS NULL " .
            "AND COALESCE(o.costing_method,:old_default,c.costing_method)<>COALESCE(o.costing_method,:new_default,c.costing_method))"
        );
        $query->execute(['company'=>$companyId,'old_default'=>$oldMethod,'new_default'=>$newMethod]);
        if ((bool)$query->fetchColumn()) throw new InvalidArgumentException('The company cost method cannot change while stock is on hand for a category that inherits it. Set up an approved revaluation first.');
    }

    private function assertCategoryCostChangesAllowed(array $rows, int $companyId, string $oldDefault, string $newDefault): void
    {
        $productLocks = $this->pdo->prepare('SELECT id FROM products WHERE company_id=:company AND category_id=:category ORDER BY id FOR UPDATE');
        $query = $this->pdo->prepare(
            "SELECT c.costing_method,o.costing_method AS current_override,EXISTS(SELECT 1 FROM products p " .
            "JOIN stock_quants q ON q.company_id=p.company_id AND q.product_id=p.id AND q.quantity>0 " .
            "JOIN stock_locations l ON l.id=q.location_id AND l.location_usage IN ('internal','transit') " .
            "WHERE p.company_id=:stock_company AND p.category_id=c.id) AS has_stock " .
            "FROM product_categories c LEFT JOIN product_category_accounting_overrides o " .
            "ON o.company_id=:override_company AND o.category_id=c.id WHERE c.id=:category " .
            "AND c.active=1 AND (c.company_id IS NULL OR c.company_id=:category_company) FOR UPDATE"
        );
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !array_key_exists('id', $row)) continue;
            $categoryId = $this->positiveId($row['id'], 'Product Category');
            $newOverride = $this->enum($row['costing_method'] ?? null, ['standard','fifo','average'], 'Category Cost Method', true);
            $productLocks->execute(['company'=>$companyId,'category'=>$categoryId]);
            $productLocks->fetchAll(PDO::FETCH_COLUMN);
            $query->execute(['stock_company'=>$companyId,'override_company'=>$companyId,'category'=>$categoryId,'category_company'=>$companyId]);
            $current = $query->fetch();
            if (!$current) continue;
            $oldEffective = (string)($current['current_override'] ?? $oldDefault);
            $newEffective = (string)($newOverride ?? $newDefault);
            if ((int)$current['has_stock'] === 1 && $oldEffective !== $newEffective) {
                throw new InvalidArgumentException('Cost method cannot change for category ' . ($index + 1) . ' while its products have stock on hand. Set up an approved revaluation first.');
            }
        }
    }

    private function nullableId(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') return null;
        return $this->positiveId($value, $label);
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' must be a positive integer.');
        return $id;
    }

    private function enum(mixed $value, array $allowed, string $label, bool $nullable = false): ?string
    {
        if ($nullable && ($value === null || $value === '')) return null;
        $value = (string)$value;
        if (!in_array($value, $allowed, true)) throw new InvalidArgumentException($label . ' selection is invalid.');
        return $value;
    }

    private function audit(int $companyId, int $actorId, string $event, string $model, array $old, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,NULL,:old,:new,:ip)')
            ->execute(['company'=>$companyId,'user'=>$actorId,'event'=>$event,'model'=>$model,'old'=>json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'new'=>json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'ip'=>$ip]);
    }
}
