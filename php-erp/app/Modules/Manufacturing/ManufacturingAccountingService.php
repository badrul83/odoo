<?php
declare(strict_types=1);

namespace Erp\Modules\Manufacturing;

use Erp\Modules\Accounting\AccountingDateGuard;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/** Posts the Odoo 20 Anglo-Saxon perpetual valuation counterpart for completed MOs. */
final class ManufacturingAccountingService
{
    public function __construct(private readonly PDO $pdo) {}

    public function postCompletedOrder(int $orderId, int $actorId): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new InvalidArgumentException('Manufacturing accounting must be posted in the production transaction.');
        }

        $orderQuery = $this->pdo->prepare(
            'SELECT mo.id,mo.company_id,mo.reference,mo.production_location_id,mo.done_at,mo.accounting_move_id,' .
            'c.currency_code,c.timezone,cur.decimal_places,s.accounting_standard,s.variation_account_id,s.journal_id,' .
            'loc.cost_of_production_account_id ' .
            'FROM manufacturing_orders mo JOIN companies c ON c.id=mo.company_id ' .
            'JOIN currencies cur ON cur.currency_code=c.currency_code ' .
            'JOIN stock_locations loc ON loc.id=mo.production_location_id AND loc.company_id=mo.company_id ' .
            'LEFT JOIN inventory_accounting_settings s ON s.company_id=mo.company_id ' .
            'WHERE mo.id=:order_id AND mo.state=\'done\' FOR UPDATE'
        );
        $orderQuery->execute(['order_id' => $orderId]);
        $order = $orderQuery->fetch();
        if (!$order) {
            throw new InvalidArgumentException('Manufacturing Order is not ready for accounting posting.');
        }

        if ($order['accounting_move_id'] !== null) {
            throw new InvalidArgumentException('This Manufacturing Order already has a linked accounting entry.');
        }

        // Odoo 20 posts production-location valuation counterparts for Anglo-Saxon perpetual valuation.
        if ($order['accounting_standard'] !== 'anglo_saxon') {
            return null;
        }

        $movesQuery = $this->pdo->prepare(
            'SELECT m.id,m.reference,m.valuation_value,m.source_location_id,m.destination_location_id,' .
            'src.location_usage AS source_usage,dst.location_usage AS destination_usage,p.name AS product_name,' .
            'COALESCE(o.valuation_method,s.valuation_method,\'periodic\') AS valuation_method,' .
            'COALESCE(o.valuation_account_id,s.valuation_account_id) AS valuation_account_id ' .
            'FROM stock_moves m JOIN products p ON p.id=m.product_id ' .
            'JOIN stock_locations src ON src.id=m.source_location_id JOIN stock_locations dst ON dst.id=m.destination_location_id ' .
            'JOIN product_categories c ON c.id=p.category_id ' .
            'LEFT JOIN inventory_accounting_settings s ON s.company_id=m.company_id ' .
            'LEFT JOIN product_category_accounting_overrides o ON o.company_id=m.company_id AND o.category_id=p.category_id ' .
            'WHERE m.company_id=:company AND m.origin_model=\'manufacturing\' AND m.origin_record_id=:order_id AND m.state=\'done\' ' .
            'AND (src.location_usage=\'production\' OR dst.location_usage=\'production\') ORDER BY m.id'
        );
        $movesQuery->execute(['company' => $order['company_id'], 'order_id' => $orderId]);
        $perpetualMoves = array_values(array_filter(
            $movesQuery->fetchAll(),
            static fn(array $move): bool => $move['valuation_method'] === 'perpetual' && trim((string)$move['valuation_value'], '-0.') !== ''
        ));
        if (!$perpetualMoves) return null;

        $journalId = (int)($order['journal_id'] ?? 0);
        $journalQuery = $this->pdo->prepare("SELECT id FROM accounting_journals WHERE id=:id AND company_id=:company AND active=1 AND journal_type='general' FOR UPDATE");
        $journalQuery->execute(['id' => $journalId, 'company' => $order['company_id']]);
        if (!$journalQuery->fetchColumn()) {
            throw new InvalidArgumentException('Configure an active General Journal in Inventory Accounting before completing a perpetual Manufacturing Order.');
        }

        $productionAccountId = (int)($order['cost_of_production_account_id'] ?? $order['variation_account_id'] ?? 0);
        $productionAccount = $this->account($productionAccountId, (int)$order['company_id']);
        if (!$productionAccount || !in_array($productionAccount['account_type'], ['asset_current','expense','expense_other','expense_direct_cost'], true)) {
            throw new InvalidArgumentException('Set an active Cost of Production account on the Production location, or configure the company Variation Account.');
        }

        $journalLines = [];
        $places = (int)$order['decimal_places'];
        if ($places < 0 || $places > 6) throw new InvalidArgumentException('Company currency precision is outside the supported range.');
        foreach ($perpetualMoves as $move) {
            $valuation = $this->account((int)($move['valuation_account_id'] ?? 0), (int)$order['company_id']);
            if (!$valuation || !in_array($valuation['account_type'], ['asset_current','asset_non_current'], true)) {
                throw new InvalidArgumentException('Set an active Stock Valuation asset account for product "' . $move['product_name'] . '" before posting its production valuation.');
            }

            $sourceIsProduction = $move['source_usage'] === 'production';
            $destinationIsProduction = $move['destination_usage'] === 'production';
            if ($sourceIsProduction === $destinationIsProduction) {
                throw new InvalidArgumentException('A production valuation move must cross the Production location boundary.');
            }
            $signedValue = (string)$move['valuation_value'];
            if (trim($signedValue, '-0.') === '') continue;
            if (($sourceIsProduction && str_starts_with($signedValue, '-')) || ($destinationIsProduction && !str_starts_with($signedValue, '-'))) {
                throw new InvalidArgumentException('Production stock movement direction does not match its valuation sign.');
            }
            $minor = $this->amountToMinor(ltrim($signedValue, '-'), $places);
            if ($minor === '0') continue;

            if ($sourceIsProduction) {
                $this->addLine($journalLines, (int)$valuation['id'], 'Production receipt · ' . $move['reference'], $minor, '0');
                $this->addLine($journalLines, $productionAccountId, 'Cost of Production · ' . $move['reference'], '0', $minor);
            } else {
                $this->addLine($journalLines, $productionAccountId, 'Cost of Production · ' . $move['reference'], $minor, '0');
                $this->addLine($journalLines, (int)$valuation['id'], 'Component consumption · ' . $move['reference'], '0', $minor);
            }
        }

        if (!$journalLines) return null;
        $debitMinor = '0';
        $creditMinor = '0';
        foreach ($journalLines as $line) {
            $debitMinor = $this->addDigits($debitMinor, $line['debit_minor']);
            $creditMinor = $this->addDigits($creditMinor, $line['credit_minor']);
        }
        if ($debitMinor === '0' || $debitMinor !== $creditMinor) {
            throw new InvalidArgumentException('Manufacturing valuation journal lines do not balance at company currency precision.');
        }

        $date = $this->accountingDate((string)$order['done_at'], (string)$order['timezone']);
        AccountingDateGuard::lockAndAssertOpen($this->pdo, (int)$order['company_id'], $date);
        $name = $this->nextName((int)$order['company_id'], (int)substr($date, 0, 4));
        $reference = (string)($order['reference'] ?: ('MO-' . $orderId));
        $currency = (string)$order['currency_code'];
        $insertMove = $this->pdo->prepare(
            "INSERT INTO accounting_moves (company_id,name,journal_id,move_type,reference_model,reference_id,reference,accounting_date,currency_code,state,total_debit,total_credit,created_by,posted_by,posted_at) " .
            "VALUES (:company,:name,:journal,'manufacturing_order','manufacturing_order',:reference_id,:reference,:date,:currency,'posted',:debit,:credit,:actor,:posted_by,UTC_TIMESTAMP())"
        );
        $insertMove->execute([
            'company' => $order['company_id'], 'name' => $name, 'journal' => $journalId, 'reference_id' => $orderId,
            'reference' => $reference, 'date' => $date, 'currency' => $currency,
            'debit' => $this->minorToAmount($debitMinor, $places), 'credit' => $this->minorToAmount($creditMinor, $places),
            'actor' => $actorId, 'posted_by' => $actorId,
        ]);
        $moveId = (int)$this->pdo->lastInsertId();
        $insertLine = $this->pdo->prepare(
            'INSERT INTO accounting_move_lines (move_id,sequence,account_id,contact_id,label,debit,credit,currency_code,amount_currency) ' .
            'VALUES (:move,:sequence,:account,NULL,:label,:debit,:credit,:currency,:amount_currency)'
        );
        $sequence = 10;
        foreach ($journalLines as $line) {
            $debit = $this->minorToAmount($line['debit_minor'], $places);
            $credit = $this->minorToAmount($line['credit_minor'], $places);
            $insertLine->execute([
                'move' => $moveId, 'sequence' => $sequence, 'account' => $line['account_id'], 'label' => $line['label'],
                'debit' => $debit, 'credit' => $credit, 'currency' => $currency,
                'amount_currency' => $line['debit_minor'] !== '0' ? $debit : '-' . $credit,
            ]);
            $sequence += 10;
        }
        $link = $this->pdo->prepare('UPDATE manufacturing_orders SET accounting_move_id=:move WHERE id=:id AND accounting_move_id IS NULL');
        $link->execute(['move' => $moveId, 'id' => $orderId]);
        if ($link->rowCount() !== 1) {
            throw new InvalidArgumentException('Manufacturing Order accounting link was changed concurrently.');
        }
        $this->audit((int)$order['company_id'], $actorId, $orderId, $moveId, $name, $date, $debitMinor, $creditMinor, $places);
        return ['id' => $moveId, 'name' => $name, 'accounting_date' => $date, 'total_debit' => $this->minorToAmount($debitMinor, $places), 'total_credit' => $this->minorToAmount($creditMinor, $places)];
    }

    private function account(int $id, int $companyId): ?array
    {
        if ($id < 1) return null;
        $query = $this->pdo->prepare('SELECT id,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');
        $query->execute(['id' => $id, 'company' => $companyId]);
        return $query->fetch() ?: null;
    }

    private function nextName(int $companyId, int $year): string
    {
        $this->pdo->prepare('INSERT INTO manufacturing_accounting_sequences (company_id,sequence_year,last_number) VALUES (:company,:year,0) ON DUPLICATE KEY UPDATE last_number=last_number')->execute(['company' => $companyId, 'year' => $year]);
        $query = $this->pdo->prepare('SELECT last_number FROM manufacturing_accounting_sequences WHERE company_id=:company AND sequence_year=:year FOR UPDATE');
        $query->execute(['company' => $companyId, 'year' => $year]);
        $next = (int)$query->fetchColumn() + 1;
        $this->pdo->prepare('UPDATE manufacturing_accounting_sequences SET last_number=:number WHERE company_id=:company AND sequence_year=:year')->execute(['number' => $next, 'company' => $companyId, 'year' => $year]);
        return sprintf('MRP/%04d/%05d', $year, $next);
    }

    private function accountingDate(string $utcDateTime, string $timezone): string
    {
        try {
            return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Throwable) {
            throw new InvalidArgumentException('The company timezone or Manufacturing Order completion time is invalid.');
        }
    }

    private function addLine(array &$lines, int $accountId, string $label, string $debitMinor, string $creditMinor): void
    {
        $key = $accountId . ':' . ($debitMinor !== '0' ? 'debit' : 'credit');
        if (!isset($lines[$key])) $lines[$key] = ['account_id' => $accountId, 'label' => $label, 'debit_minor' => '0', 'credit_minor' => '0'];
        $field = $debitMinor !== '0' ? 'debit_minor' : 'credit_minor';
        $lines[$key][$field] = $this->addDigits($lines[$key][$field], $debitMinor !== '0' ? $debitMinor : $creditMinor);
    }

    private function amountToMinor(string $amount, int $places): string
    {
        if (!preg_match('/^(\d{1,14})(?:\.(\d{1,6}))?$/D', $amount, $parts)) {
            throw new InvalidArgumentException('Stock valuation amount exceeds supported precision.');
        }
        $whole = ltrim($parts[1], '0') ?: '0';
        $fraction = str_pad($parts[2] ?? '', 6, '0');
        $kept = substr($fraction, 0, $places);
        $minor = ltrim($whole . $kept, '0') ?: '0';
        if ($places < 6 && (int)$fraction[$places] >= 5) $minor = $this->addDigits($minor, '1');
        return $minor;
    }

    private function minorToAmount(string $minor, int $places): string
    {
        if ($places === 0) return $minor;
        $digits = str_pad($minor, $places + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$places) . '.' . substr($digits, -$places);
    }

    private function addDigits(string $left, string $right): string
    {
        $i = strlen($left) - 1; $j = strlen($right) - 1; $carry = 0; $result = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = ($i >= 0 ? (int)$left[$i--] : 0) + ($j >= 0 ? (int)$right[$j--] : 0) + $carry;
            $result = (string)($sum % 10) . $result; $carry = intdiv($sum, 10);
        }
        return ltrim($result, '0') ?: '0';
    }

    private function audit(int $companyId, int $actorId, int $orderId, int $moveId, string $name, string $date, string $debit, string $credit, int $places): void
    {
        $query = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,new_values) VALUES (:company,:user,\'manufacturing.accounting.posted\',\'manufacturing_order\',:record,:values)');
        $query->execute(['company' => $companyId, 'user' => $actorId, 'record' => $orderId, 'values' => json_encode([
            'accounting_move_id' => $moveId, 'accounting_move_name' => $name, 'accounting_date' => $date,
            'debit' => $this->minorToAmount($debit, $places), 'credit' => $this->minorToAmount($credit, $places),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
