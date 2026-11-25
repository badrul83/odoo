<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

use InvalidArgumentException;
use PDO;

final class AccountingDateGuard
{
    /** Serialize ledger posting against stock valuation closes and reject backdating into a posted close. */
    public static function lockAndAssertOpen(PDO $pdo, int $companyId, string $accountingDate): void
    {
        $settings = $pdo->prepare('SELECT company_id FROM inventory_accounting_settings WHERE company_id=:company FOR UPDATE');
        $settings->execute(['company' => $companyId]);
        $settings->fetchColumn();

        $closing = $pdo->prepare(
            "SELECT name,as_of_date FROM inventory_valuation_closings " .
            "WHERE company_id=:company AND state='posted' AND as_of_date>=:accounting_date " .
            'ORDER BY as_of_date DESC,id DESC LIMIT 1'
        );
        $closing->execute(['company' => $companyId, 'accounting_date' => $accountingDate]);
        $lock = $closing->fetch();
        if ($lock) {
            throw new InvalidArgumentException(sprintf(
                'Accounting date %s is locked by inventory valuation closing %s dated %s. Post this transaction on a date after the closing date.',
                $accountingDate,
                $lock['name'],
                $lock['as_of_date'],
            ));
        }
    }
}
