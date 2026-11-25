<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class InventoryValuationClosingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function report(mixed $rawCompanyId, mixed $rawAsOfDate, mixed $rawOffset, UserContext $user, Authorization $authorization): array
    {
        $companyId = $this->companyId($rawCompanyId, $user, $authorization);
        $asOfDate = $this->date($rawAsOfDate ?? date('Y-m-d'));
        $configuration = $this->configuration($companyId);
        $snapshot = $this->snapshot($companyId, $asOfDate, $configuration);
        $offset = max(0, min(1000000, (int)$rawOffset));
        $historyCountQuery = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_valuation_closings WHERE company_id=:company');
        $historyCountQuery->execute(['company' => $companyId]);
        $historyTotal = (int)$historyCountQuery->fetchColumn();
        $history = $this->history($companyId, $offset, 50);

        return [
            'company_id' => $companyId,
            'currency_code' => (string)$configuration['currency_code'],
            'decimal_places' => (int)$configuration['decimal_places'],
            'as_of_date' => $asOfDate,
            'physical_value' => $this->minorToAmount($snapshot['physical_minor'], (int)$configuration['decimal_places']),
            'accounting_value' => $this->minorToAmount($snapshot['accounting_minor'], (int)$configuration['decimal_places']),
            'difference_value' => $this->minorToAmount($snapshot['difference_minor'], (int)$configuration['decimal_places']),
            'groups' => $snapshot['groups'],
            'closings' => $history,
            'closings_total' => $historyTotal,
            'closings_offset' => $offset,
            'closings_limit' => 50,
        ];
    }

    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT c.*,j.code AS journal_code,j.name AS journal_name,m.name AS accounting_move_name,m.state AS accounting_move_state ' .
            'FROM inventory_valuation_closings c JOIN accounting_journals j ON j.id=c.journal_id ' .
            'LEFT JOIN accounting_moves m ON m.id=c.accounting_move_id WHERE c.id=:id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $closing = $query->fetch();
        if (!$closing || !$authorization->canAccessCompany((int)$closing['company_id'])) return null;
        $lines = $this->pdo->prepare(
            'SELECT l.id,l.sequence,l.valuation_account_id,va.code AS valuation_account_code,va.name AS valuation_account_name,' .
            'l.variation_account_id,ra.code AS variation_account_code,ra.name AS variation_account_name,' .
            'l.physical_value,l.accounting_value,l.difference_value ' .
            'FROM inventory_valuation_closing_lines l JOIN accounting_accounts va ON va.id=l.valuation_account_id ' .
            'JOIN accounting_accounts ra ON ra.id=l.variation_account_id WHERE l.closing_id=:closing ORDER BY l.sequence,l.id'
        );
        $lines->execute(['closing' => $id]);
        $closing['lines'] = $lines->fetchAll();
        return $closing;
    }

    public function generate(array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','as_of_date']);
        if ($unknown) throw new InvalidArgumentException('Unsupported stock closing fields: ' . implode(', ', $unknown));
        $companyId = $this->companyId($input['company_id'] ?? null, $user, $authorization);
        $asOfDate = $this->date($input['as_of_date'] ?? null);
        $this->pdo->beginTransaction();
        try {
            $configuration = $this->lockConfiguration($companyId);
            $this->lockProducts($companyId);
            $this->assertNoLaterPostedClosing($companyId, $asOfDate);
            $snapshot = $this->snapshot($companyId, $asOfDate, $configuration);
            $existingQuery = $this->pdo->prepare(
                "SELECT id,state FROM inventory_valuation_closings WHERE company_id=:company AND as_of_date=:as_of " .
                "AND state IN ('draft','posted') ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $existingQuery->execute(['company' => $companyId, 'as_of' => $asOfDate]);
            $existing = $existingQuery->fetch();
            if ($existing) {
                $this->pdo->commit();
                if ($existing['state'] === 'draft') $this->assertSavedSnapshotMatches((int)$existing['id'], $snapshot, (int)$configuration['decimal_places']);
                return $this->find((int)$existing['id'], $user, $authorization) ?? throw new InvalidArgumentException('The stock closing is outside the allowed companies.');
            }
            if (!array_filter($snapshot['groups'], static fn(array $group): bool => $group['difference_minor'] !== 0)) {
                throw new InvalidArgumentException('Inventory and posted accounting values already match for this date; no closing entry is needed.');
            }

            $this->assertClosingAccountMapping($snapshot['groups'], $companyId);
            $places = (int)$configuration['decimal_places'];
            $yearQuery = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_valuation_closings WHERE company_id=:company AND YEAR(as_of_date)=:year');
            $yearQuery->execute(['company' => $companyId, 'year' => (int)substr($asOfDate, 0, 4)]);
            $number = (int)$yearQuery->fetchColumn() + 1;
            $name = sprintf('STK/CL/%s/%05d', substr($asOfDate, 0, 4), $number);
            $physical = $this->minorToAmount($snapshot['physical_minor'], $places);
            $accounting = $this->minorToAmount($snapshot['accounting_minor'], $places);
            $difference = $this->minorToAmount($snapshot['difference_minor'], $places);
            $this->pdo->prepare(
                "INSERT INTO inventory_valuation_closings (company_id,name,as_of_date,currency_code,state,physical_value,accounting_value,difference_value,journal_id,created_by,updated_by) " .
                "VALUES (:company,:name,:as_of,:currency,'draft',:physical,:accounting,:difference,:journal,:actor,:updater)"
            )->execute([
                'company' => $companyId, 'name' => $name, 'as_of' => $asOfDate, 'currency' => $configuration['currency_code'],
                'physical' => $physical, 'accounting' => $accounting, 'difference' => $difference,
                'journal' => $configuration['journal_id'], 'actor' => $actorId, 'updater' => $actorId,
            ]);
            $closingId = (int)$this->pdo->lastInsertId();
            $closingLineInsert = $this->pdo->prepare(
                'INSERT INTO inventory_valuation_closing_lines (closing_id,sequence,valuation_account_id,variation_account_id,physical_value,accounting_value,difference_value) ' .
                'VALUES (:closing,:sequence,:valuation_account,:variation_account,:physical,:accounting,:difference)'
            );
            foreach ($snapshot['groups'] as $index => $group) {
                $closingLineInsert->execute([
                    'closing' => $closingId, 'sequence' => ($index + 1) * 10,
                    'valuation_account' => $group['valuation_account_id'], 'variation_account' => $group['variation_account_id'],
                    'physical' => $this->minorToAmount($group['physical_minor'], $places),
                    'accounting' => $this->minorToAmount($group['accounting_minor'], $places),
                    'difference' => $this->minorToAmount($group['difference_minor'], $places),
                ]);
            }

            $journalTotals = $this->journalTotals($snapshot['groups']);
            $this->pdo->prepare(
                "INSERT INTO accounting_moves (company_id,name,journal_id,move_type,reference_model,reference_id,reference,accounting_date,currency_code,state,total_debit,total_credit,created_by) " .
                "VALUES (:company,:name,:journal,'inventory_valuation','inventory_valuation_closing',:closing,:reference,:date,:currency,'draft',:debit,:credit,:actor)"
            )->execute([
                'company' => $companyId, 'name' => $name, 'journal' => $configuration['journal_id'], 'closing' => $closingId,
                'reference' => 'Inventory Valuation Closing · ' . $asOfDate, 'date' => $asOfDate, 'currency' => $configuration['currency_code'],
                'debit' => $this->minorToAmount($journalTotals['debit'], $places), 'credit' => $this->minorToAmount($journalTotals['credit'], $places), 'actor' => $actorId,
            ]);
            $moveId = (int)$this->pdo->lastInsertId();
            $moveLineInsert = $this->pdo->prepare(
                'INSERT INTO accounting_move_lines (move_id,sequence,account_id,contact_id,label,debit,credit,currency_code,amount_currency) ' .
                'VALUES (:move,:sequence,:account,NULL,:label,:debit,:credit,:currency,:amount_currency)'
            );
            $sequence = 10;
            foreach ($snapshot['groups'] as $group) {
                $delta = (int)$group['difference_minor'];
                if ($delta === 0) continue;
                $amount = $this->minorToAmount(abs($delta), $places);
                $assetDebit = $delta > 0;
                $debitAccount = $assetDebit ? $group['valuation_account_id'] : $group['variation_account_id'];
                $creditAccount = $assetDebit ? $group['variation_account_id'] : $group['valuation_account_id'];
                $moveLineInsert->execute([
                    'move' => $moveId, 'sequence' => $sequence, 'account' => $debitAccount,
                    'label' => 'Inventory valuation adjustment · ' . $asOfDate,
                    'debit' => $amount, 'credit' => '0.000000', 'currency' => $configuration['currency_code'], 'amount_currency' => $amount,
                ]);
                $sequence += 10;
                $moveLineInsert->execute([
                    'move' => $moveId, 'sequence' => $sequence, 'account' => $creditAccount,
                    'label' => 'Inventory valuation adjustment · ' . $asOfDate,
                    'debit' => '0.000000', 'credit' => $amount, 'currency' => $configuration['currency_code'], 'amount_currency' => '-' . $amount,
                ]);
                $sequence += 10;
            }
            $this->pdo->prepare('UPDATE inventory_valuation_closings SET accounting_move_id=:move WHERE id=:id AND state=\'draft\'')
                ->execute(['move' => $moveId, 'id' => $closingId]);
            $this->audit($companyId, $actorId, $closingId, 'inventory_valuation_closing', 'inventory_valuation_closing_created', null, [
                'name' => $name, 'as_of_date' => $asOfDate, 'physical_value' => $physical, 'accounting_value' => $accounting,
                'difference_value' => $difference, 'accounting_move_id' => $moveId,
            ], $remoteAddress);
            $this->audit($companyId, $actorId, $moveId, 'accounting_move', 'inventory_valuation_entry_created', null, [
                'name' => $name, 'reference_model' => 'inventory_valuation_closing', 'reference_id' => $closingId,
                'total_debit' => $this->minorToAmount($journalTotals['debit'], $places), 'total_credit' => $this->minorToAmount($journalTotals['credit'], $places),
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($closingId, $user, $authorization) ?? throw new InvalidArgumentException('The closing entry was created but could not be reloaded.');
    }

    public function post(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $companyId = $this->authorizedCompanyForClosing($id, $authorization);
        $this->pdo->beginTransaction();
        try {
            $configuration = $this->lockConfiguration($companyId);
            $this->lockProducts($companyId);
            $lock = $this->pdo->prepare('SELECT * FROM inventory_valuation_closings WHERE id=:id AND company_id=:company FOR UPDATE');
            $lock->execute(['id' => $id, 'company' => $companyId]);
            $closing = $lock->fetch();
            if (!$closing) throw new InvalidArgumentException('Inventory valuation closing was not found in this company.');
            if ($closing['state'] === 'posted') { $this->pdo->commit(); return $this->find($id, $user, $authorization) ?? []; }
            if ($closing['state'] !== 'draft') throw new InvalidArgumentException('Only a draft inventory valuation closing can be posted.');
            $this->assertNoLaterPostedClosing($companyId, (string)$closing['as_of_date']);
            $snapshot = $this->snapshot($companyId, (string)$closing['as_of_date'], $configuration);
            $this->assertSavedSnapshotMatches($id, $snapshot, (int)$configuration['decimal_places']);
            $moveQuery = $this->pdo->prepare('SELECT * FROM accounting_moves WHERE id=:move AND company_id=:company AND reference_model=\'inventory_valuation_closing\' AND reference_id=:closing FOR UPDATE');
            $moveQuery->execute(['move' => $closing['accounting_move_id'], 'company' => $companyId, 'closing' => $id]);
            $move = $moveQuery->fetch();
            if (!$move || $move['state'] !== 'draft' || (int)$move['journal_id'] !== (int)$closing['journal_id'] || $move['accounting_date'] !== $closing['as_of_date']) {
                throw new InvalidArgumentException('The linked draft journal entry is missing or was changed. Discard this closing and generate it again.');
            }
            $this->assertJournalLinesMatch($id, (int)$move['id'], $snapshot['groups'], (int)$configuration['decimal_places']);
            $this->pdo->prepare("UPDATE accounting_moves SET state='posted',posted_by=:actor,posted_at=UTC_TIMESTAMP() WHERE id=:id AND state='draft'")
                ->execute(['actor' => $actorId, 'id' => $move['id']]);
            $this->pdo->prepare("UPDATE inventory_valuation_closings SET state='posted',posted_by=:actor,posted_at=UTC_TIMESTAMP(),updated_by=:updater WHERE id=:id AND state='draft'")
                ->execute(['actor' => $actorId, 'updater' => $actorId, 'id' => $id]);
            $this->audit($companyId, $actorId, (int)$move['id'], 'accounting_move', 'inventory_valuation_entry_posted', ['state' => 'draft'], [
                'state' => 'posted', 'name' => $move['name'], 'inventory_valuation_closing_id' => $id,
                'total_debit' => $move['total_debit'], 'total_credit' => $move['total_credit'],
            ], $remoteAddress);
            $this->audit($companyId, $actorId, $id, 'inventory_valuation_closing', 'inventory_valuation_closing_posted', ['state' => 'draft'], [
                'state' => 'posted', 'name' => $closing['name'], 'as_of_date' => $closing['as_of_date'], 'accounting_move_id' => (int)$move['id'],
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('The closing was posted but could not be reloaded.');
    }

    public function discard(int $id, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $companyId = $this->authorizedCompanyForClosing($id, $authorization);
        $this->pdo->beginTransaction();
        try {
            $this->lockConfiguration($companyId);
            $lock = $this->pdo->prepare('SELECT * FROM inventory_valuation_closings WHERE id=:id AND company_id=:company FOR UPDATE');
            $lock->execute(['id' => $id, 'company' => $companyId]);
            $closing = $lock->fetch();
            if (!$closing) throw new InvalidArgumentException('Inventory valuation closing was not found in this company.');
            if ($closing['state'] === 'discarded') { $this->pdo->commit(); return $this->find($id, $user, $authorization) ?? []; }
            if ($closing['state'] !== 'draft') throw new InvalidArgumentException('Only a draft inventory valuation closing can be discarded.');
            $moveId = (int)($closing['accounting_move_id'] ?? 0);
            if ($moveId > 0) {
                $moveLock = $this->pdo->prepare('SELECT state FROM accounting_moves WHERE id=:id AND company_id=:company FOR UPDATE');
                $moveLock->execute(['id' => $moveId, 'company' => $companyId]);
                if ($moveLock->fetchColumn() !== 'draft') throw new InvalidArgumentException('Only the unposted draft journal entry can be discarded.');
                $this->pdo->prepare('UPDATE inventory_valuation_closings SET accounting_move_id=NULL,state=\'discarded\',updated_by=:actor WHERE id=:id')
                    ->execute(['actor' => $actorId, 'id' => $id]);
                $this->pdo->prepare('DELETE FROM accounting_move_lines WHERE move_id=:move')->execute(['move' => $moveId]);
                $this->pdo->prepare('DELETE FROM accounting_moves WHERE id=:move AND state=\'draft\'')->execute(['move' => $moveId]);
            } else {
                $this->pdo->prepare('UPDATE inventory_valuation_closings SET state=\'discarded\',updated_by=:actor WHERE id=:id')
                    ->execute(['actor' => $actorId, 'id' => $id]);
            }
            $this->audit($companyId, $actorId, $id, 'inventory_valuation_closing', 'inventory_valuation_closing_discarded', ['state' => 'draft'], [
                'state' => 'discarded', 'name' => $closing['name'], 'accounting_move_id' => $moveId ?: null,
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('The closing was discarded but could not be reloaded.');
    }

    private function snapshot(int $companyId, string $asOfDate, array $configuration): array
    {
        $places = (int)$configuration['decimal_places'];
        $defaultValuation = (int)$configuration['valuation_account_id'];
        $defaultVariation = (int)$configuration['variation_account_id'];
        $groups = [$defaultValuation => ['valuation_account_id' => $defaultValuation, 'variation_account_id' => $defaultVariation, 'physical_raw' => '0.000000']];
        $query = $this->pdo->prepare(
            'SELECT COALESCE(o.valuation_account_id,:default_valuation) AS valuation_account_id,' .
            'COALESCE(o.variation_account_id,:default_variation) AS variation_account_id,' .
            'CAST(COALESCE(SUM(v.physical_value),0) AS DECIMAL(20,6)) AS physical_value ' .
            'FROM products p JOIN product_categories c ON c.id=p.category_id ' .
            'LEFT JOIN product_category_accounting_overrides o ON o.company_id=:override_company AND o.category_id=p.category_id ' .
            'LEFT JOIN (SELECT product_id,CAST(SUM(value_delta) AS DECIMAL(20,6)) AS physical_value FROM inventory_valuation_layers ' .
            'WHERE company_id=:layer_company AND valuation_date<DATE_ADD(:layer_date,INTERVAL 1 DAY) GROUP BY product_id) v ON v.product_id=p.id ' .
            'WHERE p.company_id=:product_company GROUP BY COALESCE(o.valuation_account_id,:group_valuation),COALESCE(o.variation_account_id,:group_variation)'
        );
        $query->execute([
            'default_valuation' => $defaultValuation, 'default_variation' => $defaultVariation,
            'override_company' => $companyId, 'layer_company' => $companyId, 'layer_date' => $asOfDate,
            'product_company' => $companyId, 'group_valuation' => $defaultValuation, 'group_variation' => $defaultVariation,
        ]);
        foreach ($query->fetchAll() as $row) {
            $valuationId = (int)$row['valuation_account_id'];
            $variationId = (int)$row['variation_account_id'];
            if ($valuationId < 1 || $variationId < 1) throw new InvalidArgumentException('Set a Stock Valuation Account and Variation Account on the company or every product category before closing inventory.');
            if (isset($groups[$valuationId]) && (int)$groups[$valuationId]['variation_account_id'] !== $variationId) {
                throw new InvalidArgumentException('Each Stock Valuation Account must use one Variation Account for a closing. Use distinct Stock Valuation Accounts where categories need different variation accounts.');
            }
            $groups[$valuationId] ??= ['valuation_account_id' => $valuationId, 'variation_account_id' => $variationId, 'physical_raw' => '0.000000'];
            $groups[$valuationId]['physical_raw'] = $this->addDecimal((string)$groups[$valuationId]['physical_raw'], (string)$row['physical_value']);
        }

        $snapshotGroups = [];
        $physicalTotal = 0;
        $accountingTotal = 0;
        $differenceTotal = 0;
        foreach ($groups as $group) {
            $valuation = $this->account((int)$group['valuation_account_id'], $companyId, ['asset_current','asset_non_current'], 'Stock Valuation Account');
            $variation = $this->account((int)$group['variation_account_id'], $companyId, ['asset_current','expense','expense_other','expense_direct_cost'], 'Variation Account');
            if ($valuation['id'] === $variation['id']) throw new InvalidArgumentException('Stock Valuation Account and Variation Account must be different accounts.');
            $balance = $this->accountingBalance((int)$valuation['id'], $companyId, $asOfDate);
            $physicalMinor = $this->amountToMinor((string)$group['physical_raw'], $places);
            $accountingMinor = $this->amountToMinor($balance, $places);
            $differenceMinor = $physicalMinor - $accountingMinor;
            $physicalTotal += $physicalMinor;
            $accountingTotal += $accountingMinor;
            $differenceTotal += $differenceMinor;
            $snapshotGroups[] = [
                'valuation_account_id' => (int)$valuation['id'], 'valuation_account_code' => (string)$valuation['code'], 'valuation_account_name' => (string)$valuation['name'],
                'variation_account_id' => (int)$variation['id'], 'variation_account_code' => (string)$variation['code'], 'variation_account_name' => (string)$variation['name'],
                'physical_minor' => $physicalMinor, 'accounting_minor' => $accountingMinor, 'difference_minor' => $differenceMinor,
                'physical_value' => $this->minorToAmount($physicalMinor, $places),
                'accounting_value' => $this->minorToAmount($accountingMinor, $places),
                'difference_value' => $this->minorToAmount($differenceMinor, $places),
            ];
        }
        usort($snapshotGroups, static fn(array $left, array $right): int => [$left['valuation_account_code'], $left['valuation_account_id']] <=> [$right['valuation_account_code'], $right['valuation_account_id']]);
        return ['groups' => $snapshotGroups, 'physical_minor' => $physicalTotal, 'accounting_minor' => $accountingTotal, 'difference_minor' => $differenceTotal];
    }

    private function history(int $companyId, int $offset, int $limit): array
    {
        $query = $this->pdo->prepare(
            'SELECT c.id,c.name,c.as_of_date,c.currency_code,c.state,c.physical_value,c.accounting_value,c.difference_value,' .
            'c.accounting_move_id,m.name AS accounting_move_name,m.state AS accounting_move_state ' .
            'FROM inventory_valuation_closings c LEFT JOIN accounting_moves m ON m.id=c.accounting_move_id ' .
            'WHERE c.company_id=:company ORDER BY c.as_of_date DESC,c.id DESC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':company', $companyId, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    private function lockConfiguration(int $companyId): array
    {
        $query = $this->pdo->prepare(
            'SELECT s.*,co.currency_code,cur.decimal_places FROM inventory_accounting_settings s ' .
            'JOIN companies co ON co.id=s.company_id AND co.active=1 JOIN currencies cur ON cur.currency_code=co.currency_code ' .
            'WHERE s.company_id=:company FOR UPDATE'
        );
        $query->execute(['company' => $companyId]);
        $configuration = $query->fetch();
        if (!$configuration || empty($configuration['accounting_standard']) || empty($configuration['valuation_account_id']) || empty($configuration['variation_account_id']) || empty($configuration['journal_id'])) {
            throw new InvalidArgumentException('Complete Inventory Valuation Accounting setup before generating a stock closing entry.');
        }
        if ((int)$configuration['decimal_places'] < 0 || (int)$configuration['decimal_places'] > 4) throw new InvalidArgumentException('The company currency precision is unsupported for inventory closing.');
        $this->journal((int)$configuration['journal_id'], $companyId);
        return $configuration;
    }

    private function configuration(int $companyId): array
    {
        $query = $this->pdo->prepare(
            'SELECT s.*,co.currency_code,cur.decimal_places FROM inventory_accounting_settings s ' .
            'JOIN companies co ON co.id=s.company_id AND co.active=1 JOIN currencies cur ON cur.currency_code=co.currency_code WHERE s.company_id=:company'
        );
        $query->execute(['company' => $companyId]);
        $configuration = $query->fetch();
        if (!$configuration || empty($configuration['accounting_standard']) || empty($configuration['valuation_account_id']) || empty($configuration['variation_account_id']) || empty($configuration['journal_id'])) {
            throw new InvalidArgumentException('Complete Inventory Valuation Accounting setup before reviewing a stock closing.');
        }
        $this->journal((int)$configuration['journal_id'], $companyId);
        return $configuration;
    }

    private function lockProducts(int $companyId): void
    {
        $query = $this->pdo->prepare('SELECT id FROM products WHERE company_id=:company ORDER BY id FOR UPDATE');
        $query->execute(['company' => $companyId]);
        $query->fetchAll(PDO::FETCH_COLUMN);
    }

    private function accountingBalance(int $accountId, int $companyId, string $asOfDate): string
    {
        $query = $this->pdo->prepare(
            "SELECT CAST(COALESCE(SUM(ml.debit-ml.credit),0) AS DECIMAL(20,6)) FROM accounting_move_lines ml " .
            "JOIN accounting_moves m ON m.id=ml.move_id WHERE m.company_id=:company AND m.state='posted' " .
            'AND m.accounting_date<=:as_of AND ml.account_id=:account'
        );
        $query->execute(['company' => $companyId, 'as_of' => $asOfDate, 'account' => $accountId]);
        return (string)$query->fetchColumn();
    }

    private function account(int $id, int $companyId, array $types, string $label): array
    {
        $query = $this->pdo->prepare('SELECT id,code,name,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');
        $query->execute(['id' => $id, 'company' => $companyId]);
        $account = $query->fetch();
        if (!$account || !in_array($account['account_type'], $types, true)) throw new InvalidArgumentException($label . ' must be active and have the correct account type in this company.');
        return $account;
    }

    private function journal(int $id, int $companyId): void
    {
        $query = $this->pdo->prepare("SELECT 1 FROM accounting_journals WHERE id=:id AND company_id=:company AND active=1 AND journal_type='general'");
        $query->execute(['id' => $id, 'company' => $companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Inventory Valuation Journal must be an active General Journal in this company.');
    }

    private function journalTotals(array $groups): array
    {
        $total = 0;
        foreach ($groups as $group) $total += abs((int)$group['difference_minor']);
        return ['debit' => $total, 'credit' => $total];
    }

    private function assertClosingAccountMapping(array $groups, int $companyId): void
    {
        foreach ($groups as $group) {
            $this->account((int)$group['valuation_account_id'], $companyId, ['asset_current','asset_non_current'], 'Stock Valuation Account');
            $this->account((int)$group['variation_account_id'], $companyId, ['asset_current','expense','expense_other','expense_direct_cost'], 'Variation Account');
        }
    }

    private function assertSavedSnapshotMatches(int $closingId, array $snapshot, int $places): void
    {
        $query = $this->pdo->prepare('SELECT valuation_account_id,variation_account_id,physical_value,accounting_value,difference_value FROM inventory_valuation_closing_lines WHERE closing_id=:closing ORDER BY valuation_account_id');
        $query->execute(['closing' => $closingId]);
        $saved = [];
        foreach ($query->fetchAll() as $line) $saved[(int)$line['valuation_account_id']] = $line;
        if (count($saved) !== count($snapshot['groups'])) throw new InvalidArgumentException('Stock or account balances changed after the draft was generated. Discard it and create a new valuation closing.');
        foreach ($snapshot['groups'] as $group) {
            $accountId = (int)$group['valuation_account_id'];
            $line = $saved[$accountId] ?? null;
            if (!$line || (int)$line['variation_account_id'] !== (int)$group['variation_account_id']
                || $this->amountToMinor((string)$line['physical_value'], $places) !== (int)$group['physical_minor']
                || $this->amountToMinor((string)$line['accounting_value'], $places) !== (int)$group['accounting_minor']
                || $this->amountToMinor((string)$line['difference_value'], $places) !== (int)$group['difference_minor']) {
                throw new InvalidArgumentException('Stock, account mappings, or posted balances changed after the draft was generated. Discard it and create a new valuation closing.');
            }
        }
    }

    private function assertJournalLinesMatch(int $closingId, int $moveId, array $groups, int $places): void
    {
        $query = $this->pdo->prepare('SELECT sequence,account_id,debit,credit FROM accounting_move_lines WHERE move_id=:move ORDER BY sequence,id');
        $query->execute(['move' => $moveId]);
        $actual = $query->fetchAll();
        $expected = [];
        $sequence = 10;
        foreach ($groups as $group) {
            $delta = (int)$group['difference_minor'];
            if ($delta === 0) continue;
            $amount = abs($delta);
            $expected[] = ['sequence' => $sequence, 'account_id' => $delta > 0 ? (int)$group['valuation_account_id'] : (int)$group['variation_account_id'], 'debit' => $amount, 'credit' => 0];
            $sequence += 10;
            $expected[] = ['sequence' => $sequence, 'account_id' => $delta > 0 ? (int)$group['variation_account_id'] : (int)$group['valuation_account_id'], 'debit' => 0, 'credit' => $amount];
            $sequence += 10;
        }
        if (count($actual) !== count($expected)) throw new InvalidArgumentException('The draft journal lines do not match their stock valuation snapshot. Discard and regenerate the closing entry.');
        foreach ($expected as $index => $line) {
            $posted = $actual[$index];
            if ((int)$posted['sequence'] !== $line['sequence'] || (int)$posted['account_id'] !== $line['account_id']
                || $this->amountToMinor((string)$posted['debit'], $places) !== $line['debit']
                || $this->amountToMinor((string)$posted['credit'], $places) !== $line['credit']) {
                throw new InvalidArgumentException('The draft journal lines do not match their stock valuation snapshot. Discard and regenerate the closing entry.');
            }
        }
    }

    private function assertNoLaterPostedClosing(int $companyId, string $asOfDate): void
    {
        $query = $this->pdo->prepare("SELECT as_of_date FROM inventory_valuation_closings WHERE company_id=:company AND state='posted' AND as_of_date>:as_of ORDER BY as_of_date LIMIT 1");
        $query->execute(['company' => $companyId, 'as_of' => $asOfDate]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('A later inventory valuation closing is already posted. Reconcile dates in order before posting this closing.');
    }

    private function authorizedCompanyForClosing(int $id, Authorization $authorization): int
    {
        $query = $this->pdo->prepare('SELECT company_id FROM inventory_valuation_closings WHERE id=:id LIMIT 1');
        $query->execute(['id' => $id]);
        $raw = $query->fetchColumn();
        if ($raw === false || !$authorization->canAccessCompany((int)$raw)) throw new InvalidArgumentException('Inventory valuation closing was not found or is outside the allowed companies.');
        return (int)$raw;
    }

    private function companyId(mixed $value, UserContext $user, Authorization $authorization): int
    {
        $id = $value === null || $value === '' ? $user->defaultCompanyId : $this->positiveId($value, 'Company');
        if (!$id || !$authorization->canAccessCompany($id)) throw new InvalidArgumentException('Select an allowed company for the inventory valuation report.');
        return $id;
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' must be a positive integer.');
        return $id;
    }

    private function date(mixed $value): string
    {
        $raw = trim((string)($value ?? ''));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$parsed || $parsed->format('Y-m-d') !== $raw) throw new InvalidArgumentException('As-of Date must be a valid date in YYYY-MM-DD format.');
        if ($raw > date('Y-m-d')) throw new InvalidArgumentException('Inventory cannot be closed for a future date.');
        return $raw;
    }

    private function amountToMinor(string $amount, int $places): int
    {
        if (!preg_match('/^(-?)(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D', $amount, $matches)) throw new InvalidArgumentException('Inventory values must be finite company-currency amounts below 1 trillion.');
        $fraction = str_pad($matches[3] ?? '', $places + 1, '0');
        $minor = (int)$matches[2] * (10 ** $places) + (int)substr($fraction, 0, $places);
        if ((int)($fraction[$places] ?? '0') >= 5) $minor++;
        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function minorToAmount(int $minor, int $places): string
    {
        $factor = 10 ** $places;
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        return $sign . intdiv($absolute, $factor) . '.' . str_pad((string)($absolute % $factor), $places, '0', STR_PAD_LEFT);
    }

    private function addDecimal(string $left, string $right): string
    {
        $query = $this->pdo->prepare('SELECT CAST(CAST(:left AS DECIMAL(20,6))+CAST(:right AS DECIMAL(20,6)) AS DECIMAL(20,6))');
        $query->execute(['left' => $left, 'right' => $right]);
        return (string)$query->fetchColumn();
    }

    private function audit(int $companyId, int $actorId, int $recordId, string $model, string $event, ?array $old, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $query = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');
        $query->bindValue(':company', $companyId, PDO::PARAM_INT);
        $query->bindValue(':user', $actorId, PDO::PARAM_INT);
        $query->bindValue(':event', $event);
        $query->bindValue(':model', $model);
        $query->bindValue(':record', $recordId, PDO::PARAM_INT);
        $query->bindValue(':old_values', $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $old === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query->bindValue(':new_values', json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $query->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $query->execute();
    }
}
