<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;

final class BankStatementRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(mixed $rawCompanyId, UserContext $user, Authorization $auth): array
    {
        $companyId = $this->companyId($rawCompanyId ?? $user->defaultCompanyId, $auth);
        $this->requireCompanyPermission($auth, $companyId, 'accounting.read');
        $company = $this->company($companyId);
        $query = $this->pdo->prepare("SELECT id,code,name,journal_type FROM accounting_journals WHERE company_id=:company AND active=1 AND journal_type IN ('bank','cash') ORDER BY name,id");
        $query->execute(['company' => $companyId]);
        $accounts = $this->pdo->prepare("SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company AND active=1 AND account_type NOT IN ('asset_cash','asset_receivable','liability_payable','off_balance') ORDER BY code,id");
        $accounts->execute(['company' => $companyId]);
        return ['company' => $company, 'journals' => $query->fetchAll(), 'reconciliation_accounts' => $accounts->fetchAll()];
    }

    public function search(array $filters, UserContext $user, Authorization $auth): array
    {
        $unknown = array_diff(array_keys($filters), ['company_id','limit','offset']);
        if ($unknown) throw new InvalidArgumentException('Unsupported bank statement filters: ' . implode(', ', $unknown));
        $companyId = $this->companyId($filters['company_id'] ?? $user->defaultCompanyId, $auth);
        $this->requireCompanyPermission($auth, $companyId, 'accounting.read');
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM bank_statements WHERE company_id=:company');
        $count->execute(['company' => $companyId]);
        $total = (int)$count->fetchColumn();
        $query = $this->pdo->prepare(
            "SELECT s.id,s.name,s.journal_id,j.code AS journal_code,j.name AS journal_name,s.source_file_name,s.statement_date_from,s.statement_date_to,s.opening_balance,s.closing_balance,s.currency_code,s.line_count,s.state,s.created_at,\n" .
            "(SELECT COUNT(*) FROM bank_statement_lines l LEFT JOIN bank_statement_matches cm ON cm.statement_line_id=l.id LEFT JOIN customer_credit_note_refund_matches crm ON crm.statement_line_id=l.id LEFT JOIN vendor_bank_statement_matches vm ON vm.statement_line_id=l.id LEFT JOIN vendor_credit_note_refund_matches vcrm ON vcrm.statement_line_id=l.id LEFT JOIN bank_statement_account_matches am ON am.statement_line_id=l.id WHERE l.statement_id=s.id AND cm.id IS NULL AND crm.id IS NULL AND vm.id IS NULL AND vcrm.id IS NULL AND am.id IS NULL) AS unmatched_count " .
            'FROM bank_statements s JOIN accounting_journals j ON j.id=s.journal_id WHERE s.company_id=:company ORDER BY s.statement_date_to DESC,s.id DESC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':company', $companyId, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $records = $query->fetchAll();
        foreach ($records as &$record) $this->castStatement($record);
        unset($record);
        return ['records' => $records, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function import(array $input, string $fileName, string $fileContents, UserContext $user, Authorization $auth, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','journal_id','opening_balance','closing_balance']);
        if ($unknown) throw new InvalidArgumentException('Unsupported bank statement import fields: ' . implode(', ', $unknown));
        $companyId = $this->companyId($input['company_id'] ?? null, $auth);
        $this->requireCompanyPermission($auth, $companyId, 'accounting.reconcile');
        $journalId = $this->positiveId($input['journal_id'] ?? null, 'Bank / Cash Journal');
        $safeFileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($safeFileName === '' || mb_strlen($safeFileName) > 190 || strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION)) !== 'csv') {
            throw new InvalidArgumentException('Choose a CSV file with a file name no longer than 190 characters.');
        }
        $rows = BankStatementCsv::parse($fileContents);
        $hash = hash('sha256', $fileContents);
        $this->pdo->beginTransaction();
        try {
            $journalQuery = $this->pdo->prepare(
                'SELECT j.id,j.company_id,j.code,j.name,j.journal_type,j.active,co.currency_code,cur.decimal_places ' .
                'FROM accounting_journals j JOIN companies co ON co.id=j.company_id JOIN currencies cur ON cur.currency_code=co.currency_code ' .
                'WHERE j.id=:journal AND j.company_id=:company FOR UPDATE'
            );
            $journalQuery->execute(['journal' => $journalId, 'company' => $companyId]);
            $journal = $journalQuery->fetch();
            if (!$journal || !(bool)$journal['active'] || !in_array($journal['journal_type'], ['bank','cash'], true)) {
                throw new InvalidArgumentException('Select an active Bank or Cash journal in the selected company.');
            }
            $places = (int)$journal['decimal_places'];
            $currency = (string)$journal['currency_code'];
            $openingMinor = $this->minor((string)($input['opening_balance'] ?? ''), $places, true, 'Opening Balance');
            $closingMinor = $this->minor((string)($input['closing_balance'] ?? ''), $places, true, 'Closing Balance');
            $lineMinorTotal = 0;
            $dateFrom = '9999-12-31';
            $dateTo = '0001-01-01';
            foreach ($rows as &$row) {
                if (($row['currency_code'] ?? null) !== null && $row['currency_code'] !== $currency) {
                    throw new InvalidArgumentException('CSV row ' . $row['line_number'] . ': Currency must match the selected company currency (' . $currency . ').');
                }
                $minor = $this->minor($row['amount'], $places, true, 'CSV row ' . $row['line_number'] . ' Amount');
                if ($minor === 0) throw new InvalidArgumentException('CSV row ' . $row['line_number'] . ': Amount must not be zero.');
                if (($minor > 0 && $lineMinorTotal > PHP_INT_MAX - $minor) || ($minor < 0 && $lineMinorTotal < PHP_INT_MIN - $minor)) {
                    throw new InvalidArgumentException('Statement total is outside the supported amount range.');
                }
                $lineMinorTotal += $minor;
                $row['amount'] = $this->decimal($minor, $places);
                $row['currency_code'] = $currency;
                $dateFrom = min($dateFrom, $row['transaction_date']);
                $dateTo = max($dateTo, $row['transaction_date']);
            }
            unset($row);
            if (($lineMinorTotal > 0 && $openingMinor > PHP_INT_MAX - $lineMinorTotal) || ($lineMinorTotal < 0 && $openingMinor < PHP_INT_MIN - $lineMinorTotal)) {
                throw new InvalidArgumentException('Opening Balance plus the signed CSV transactions is outside the supported amount range.');
            }
            if ($openingMinor + $lineMinorTotal !== $closingMinor) {
                throw new InvalidArgumentException('Opening Balance plus the signed CSV transactions must equal Closing Balance.');
            }

            $existing = $this->pdo->prepare('SELECT id,name,state,source_file_name,statement_date_from,statement_date_to,opening_balance,closing_balance,currency_code,line_count FROM bank_statements WHERE company_id=:company AND journal_id=:journal AND source_file_hash=:hash LIMIT 1 FOR UPDATE');
            $existing->execute(['company' => $companyId, 'journal' => $journalId, 'hash' => $hash]);
            $duplicate = $existing->fetch();
            if ($duplicate) {
                $this->pdo->commit();
                $this->castStatement($duplicate);
                $duplicate['duplicate'] = true;
                return ['statement' => $duplicate, 'duplicate' => true];
            }

            $placeholder = 'IMPORT-' . strtoupper(bin2hex(random_bytes(8)));
            $this->pdo->prepare(
                "INSERT INTO bank_statements (company_id,journal_id,name,source_file_name,source_file_hash,statement_date_from,statement_date_to,opening_balance,closing_balance,currency_code,line_count,state,created_by) " .
                "VALUES (:company,:journal,:name,:file,:hash,:date_from,:date_to,:opening,:closing,:currency,:line_count,'imported',:actor)"
            )->execute([
                'company' => $companyId, 'journal' => $journalId, 'name' => $placeholder, 'file' => $safeFileName, 'hash' => $hash,
                'date_from' => $dateFrom, 'date_to' => $dateTo, 'opening' => $this->decimal($openingMinor, $places),
                'closing' => $this->decimal($closingMinor, $places), 'currency' => $currency, 'line_count' => count($rows), 'actor' => $user->id,
            ]);
            $statementId = (int)$this->pdo->lastInsertId();
            $statementName = sprintf('BS%04d-%06d', (int)substr($dateTo, 0, 4), $statementId);
            $this->pdo->prepare('UPDATE bank_statements SET name=:name WHERE id=:id')->execute(['name' => $statementName, 'id' => $statementId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO bank_statement_lines (statement_id,line_number,transaction_date,reference,description,amount,currency_code,partner_email,external_id) ' .
                'VALUES (:statement,:line,:date,:reference,:description,:amount,:currency,:email,:external_id)'
            );
            foreach ($rows as $row) $insert->execute([
                'statement' => $statementId, 'line' => $row['line_number'], 'date' => $row['transaction_date'], 'reference' => $row['reference'],
                'description' => $row['description'], 'amount' => $row['amount'], 'currency' => $currency,
                'email' => $row['partner_email'], 'external_id' => $row['external_id'],
            ]);
            $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
            $audit = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,NULL,:new,:ip)');
            $audit->execute([
                'company' => $companyId, 'user' => $user->id, 'event' => 'bank_statement_imported', 'model' => 'bank_statement',
                'record' => $statementId, 'new' => json_encode(['name' => $statementName, 'journal_id' => $journalId, 'file_name' => $safeFileName, 'file_sha256' => $hash, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'opening_balance' => $this->decimal($openingMinor, $places), 'closing_balance' => $this->decimal($closingMinor, $places), 'line_count' => count($rows)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'ip' => $ip,
            ]);
            $this->pdo->commit();
            $statement = $this->findHeader($statementId, $user, $auth);
            return ['statement' => $statement, 'duplicate' => false];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function find(int $id, array $filters, UserContext $user, Authorization $auth): array
    {
        $statement = $this->findHeader($id, $user, $auth);
        $unknown = array_diff(array_keys($filters), ['limit','offset','line_id']);
        if ($unknown) throw new InvalidArgumentException('Unsupported bank statement detail filters: ' . implode(', ', $unknown));
        $limit = max(1, min(100, (int)($filters['limit'] ?? 100)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $focusLineId = null;
        if (($filters['line_id'] ?? '') !== '') {
            $focusLineId = filter_var($filters['line_id'], FILTER_VALIDATE_INT);
            if ($focusLineId === false || $focusLineId < 1) throw new InvalidArgumentException('Statement line ID must be a positive integer.');
            $linePosition = $this->pdo->prepare('SELECT line_number FROM bank_statement_lines WHERE id=:line AND statement_id=:statement LIMIT 1');
            $linePosition->execute(['line' => $focusLineId, 'statement' => $id]);
            $lineNumber = $linePosition->fetchColumn();
            if ($lineNumber === false) throw new InvalidArgumentException('Statement line was not found in this statement.');
            $position = $this->pdo->prepare('SELECT COUNT(*) FROM bank_statement_lines WHERE statement_id=:statement AND line_number<:line_number');
            $position->execute(['statement' => $id, 'line_number' => $lineNumber]);
            $offset = (int)$position->fetchColumn();
            $limit = 1;
        }
        $queryOffset = $focusLineId === null ? $offset : 0;
        $query = $this->pdo->prepare(
            "SELECT l.id,l.line_number,l.transaction_date,l.reference,l.description,l.amount,l.currency_code,l.partner_email,l.external_id,\n" .
            "CASE WHEN cm.id IS NOT NULL THEN 'customer' WHEN crm.id IS NOT NULL THEN 'customer_refund' WHEN vm.id IS NOT NULL THEN 'vendor' WHEN vcrm.id IS NOT NULL THEN 'vendor_refund' WHEN am.id IS NOT NULL THEN 'account' ELSE NULL END AS matched_type,\n" .
            "COALESCE(cp.name,cr.name,vp.name,vcr.name) AS payment_name,COALESCE(ci.name,crn.name,vb.name,vcn.name,aa.name) AS document_name,COALESCE(cc.name,crc.name,vc.name,vcc.name) AS partner_name,COALESCE(cmove.id,crmove.id,vmove.id,vcrmove.id,amove.id) AS accounting_move_id,COALESCE(cmove.name,crmove.name,vmove.name,vcrmove.name,amove.name) AS accounting_move_name,COALESCE(cmove.state,crmove.state,vmove.state,vcrmove.state,amove.state) AS accounting_move_state " .
            'FROM bank_statement_lines l LEFT JOIN bank_statement_matches cm ON cm.statement_line_id=l.id LEFT JOIN customer_payments cp ON cp.id=cm.payment_id LEFT JOIN customer_invoices ci ON ci.id=cp.invoice_id LEFT JOIN contacts cc ON cc.id=cp.partner_contact_id LEFT JOIN accounting_moves cmove ON cmove.id=cm.accounting_move_id ' .
            'LEFT JOIN customer_credit_note_refund_matches crm ON crm.statement_line_id=l.id LEFT JOIN customer_credit_note_refunds cr ON cr.id=crm.refund_id LEFT JOIN customer_invoices crn ON crn.id=cr.credit_note_id LEFT JOIN contacts crc ON crc.id=cr.partner_contact_id LEFT JOIN accounting_moves crmove ON crmove.id=crm.accounting_move_id ' .
            'LEFT JOIN vendor_bank_statement_matches vm ON vm.statement_line_id=l.id LEFT JOIN vendor_payments vp ON vp.id=vm.payment_id LEFT JOIN vendor_bills vb ON vb.id=vp.vendor_bill_id LEFT JOIN contacts vc ON vc.id=vp.partner_contact_id LEFT JOIN accounting_moves vmove ON vmove.id=vm.accounting_move_id ' .
            'LEFT JOIN vendor_credit_note_refund_matches vcrm ON vcrm.statement_line_id=l.id LEFT JOIN vendor_credit_note_refunds vcr ON vcr.id=vcrm.refund_id LEFT JOIN vendor_credit_notes vcn ON vcn.id=vcr.credit_note_id AND vcn.company_id=vcr.company_id LEFT JOIN contacts vcc ON vcc.id=vcr.partner_contact_id LEFT JOIN accounting_moves vcrmove ON vcrmove.id=vcrm.accounting_move_id ' .
            'LEFT JOIN bank_statement_account_matches am ON am.statement_line_id=l.id LEFT JOIN accounting_accounts aa ON aa.id=am.account_id LEFT JOIN accounting_moves amove ON amove.id=am.accounting_move_id ' .
            'WHERE l.statement_id=:statement'.($focusLineId === null ? '' : ' AND l.id=:focus_line').' ORDER BY l.line_number LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':statement', $id, PDO::PARAM_INT);
        if ($focusLineId !== null) $query->bindValue(':focus_line', $focusLineId, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $queryOffset, PDO::PARAM_INT);
        $query->execute();
        $lines = $query->fetchAll();
        foreach ($lines as &$line) $line['id'] = (int)$line['id'];
        unset($line);
        return ['statement' => $statement, 'lines' => $lines, 'limit' => $limit, 'offset' => $offset, 'next_offset' => $offset + count($lines), 'focus_line_id' => $focusLineId];
    }

    public function candidates(int $statementId, int $lineId, UserContext $user, Authorization $auth): array
    {
        $statement = $this->findHeader($statementId, $user, $auth);
        $this->requireCompanyPermission($auth, (int)$statement['company_id'], 'accounting.reconcile');
        $lineQuery = $this->pdo->prepare(
            'SELECT l.*,s.journal_id,s.company_id,s.currency_code AS statement_currency,s.state AS statement_state,co.currency_code AS company_currency,cur.decimal_places ' .
            'FROM bank_statement_lines l JOIN bank_statements s ON s.id=l.statement_id JOIN companies co ON co.id=s.company_id JOIN currencies cur ON cur.currency_code=s.currency_code ' .
            'WHERE l.id=:line AND l.statement_id=:statement LIMIT 1'
        );
        $lineQuery->execute(['line' => $lineId, 'statement' => $statementId]);
        $line = $lineQuery->fetch();
        if (!$line) throw new InvalidArgumentException('Bank statement line was not found.');
        $this->assertUnmatched($lineId);
        if ($line['statement_state'] === 'reconciled') throw new InvalidArgumentException('This bank statement is already reconciled.');
        $lineMinor = $this->minor((string)$line['amount'], (int)$line['decimal_places'], true, 'Statement Amount');
        if ($lineMinor === 0) throw new InvalidArgumentException('Statement amount must not be zero.');
        $amount = $this->decimal(abs($lineMinor), (int)$line['decimal_places']);
        $currency = (string)$line['statement_currency'];
        $company = (int)$line['company_id'];
        $journal = (int)$line['journal_id'];
        $candidates = [];
        if ((float)$line['amount'] > 0) {
            $query = $this->pdo->prepare(
                "SELECT 'customer' AS payment_type,p.id,p.name AS payment_name,p.payment_date,p.amount,p.reference,c.name AS partner_name,i.name AS document_name,p.partner_contact_id " .
                'FROM customer_payments p JOIN customer_invoices i ON i.id=p.invoice_id JOIN contacts c ON c.id=p.partner_contact_id ' .
                "WHERE p.company_id=:company AND p.journal_id=:journal AND p.currency_code=:currency AND p.amount=:amount AND p.state='in_payment' AND i.state='posted' AND i.company_id=:invoice_company AND NOT EXISTS (SELECT 1 FROM bank_statement_matches m WHERE m.payment_id=p.id) ORDER BY (LOWER(COALESCE(c.email,''))=LOWER(:email)) DESC,p.payment_date,p.id LIMIT 100"
            );
            $query->execute(['company' => $company, 'journal' => $journal, 'currency' => $currency, 'amount' => $amount, 'invoice_company' => $company, 'email' => $line['partner_email'] ?? '']);
            $candidates = $query->fetchAll();
            $vendorRefunds=$this->pdo->prepare(
                "SELECT 'vendor_refund' AS payment_type,r.id,r.name AS payment_name,r.refund_date AS payment_date,r.amount,r.reference,c.name AS partner_name,cn.name AS document_name,r.partner_contact_id " .
                'FROM vendor_credit_note_refunds r JOIN vendor_credit_notes cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id JOIN contacts c ON c.id=r.partner_contact_id ' .
                "WHERE r.company_id=:company AND r.journal_id=:journal AND r.currency_code=:currency AND r.amount=:amount AND r.state='in_payment' AND cn.state='posted' AND NOT EXISTS (SELECT 1 FROM vendor_credit_note_refund_matches m WHERE m.refund_id=r.id) ORDER BY (LOWER(COALESCE(c.email,''))=LOWER(:email)) DESC,r.refund_date,r.id LIMIT 100"
            );$vendorRefunds->execute(['company'=>$company,'journal'=>$journal,'currency'=>$currency,'amount'=>$amount,'email'=>$line['partner_email']??'']);$candidates=array_merge($candidates,$vendorRefunds->fetchAll());
        } elseif ((float)$line['amount'] < 0) {
            $query = $this->pdo->prepare(
                "SELECT 'vendor' AS payment_type,p.id,p.name AS payment_name,p.payment_date,p.amount,p.reference,c.name AS partner_name,b.name AS document_name,p.partner_contact_id " .
                'FROM vendor_payments p JOIN vendor_bills b ON b.id=p.vendor_bill_id JOIN contacts c ON c.id=p.partner_contact_id ' .
                "WHERE p.company_id=:company AND p.journal_id=:journal AND p.currency_code=:currency AND p.amount=:amount AND p.state='in_payment' AND b.state='posted' AND b.company_id=:bill_company AND NOT EXISTS (SELECT 1 FROM vendor_bank_statement_matches m WHERE m.payment_id=p.id) ORDER BY (LOWER(COALESCE(c.email,''))=LOWER(:email)) DESC,p.payment_date,p.id LIMIT 100"
            );
            $query->execute(['company' => $company, 'journal' => $journal, 'currency' => $currency, 'amount' => $amount, 'bill_company' => $company, 'email' => $line['partner_email'] ?? '']);
            $candidates = $query->fetchAll();
            $refunds=$this->pdo->prepare(
                "SELECT 'customer_refund' AS payment_type,r.id,r.name AS payment_name,r.payment_date,-r.amount AS amount,r.reference,c.name AS partner_name,cn.name AS document_name,r.partner_contact_id " .
                'FROM customer_credit_note_refunds r JOIN customer_invoices cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id JOIN contacts c ON c.id=r.partner_contact_id ' .
                "WHERE r.company_id=:company AND r.journal_id=:journal AND r.currency_code=:currency AND r.amount=:amount AND r.state='in_payment' AND cn.state='posted' AND NOT EXISTS (SELECT 1 FROM customer_credit_note_refund_matches m WHERE m.refund_id=r.id) ORDER BY (LOWER(COALESCE(c.email,''))=LOWER(:email)) DESC,r.payment_date,r.id LIMIT 100"
            );$refunds->execute(['company'=>$company,'journal'=>$journal,'currency'=>$currency,'amount'=>$amount,'email'=>$line['partner_email']??'']);$candidates=array_merge($candidates,$refunds->fetchAll());
        }
        foreach ($candidates as &$candidate) $candidate['id'] = (int)$candidate['id'];
        unset($candidate);
        return ['line_id' => $lineId, 'candidates' => $candidates];
    }

    public function categorize(int $statementId, int $lineId, array $input, UserContext $user, Authorization $auth, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['account_id','label']);
        if ($unknown) throw new InvalidArgumentException('Unsupported statement categorization fields: ' . implode(', ', $unknown));
        $statement = $this->findHeader($statementId, $user, $auth);
        $companyId = (int)$statement['company_id'];
        $this->requireCompanyPermission($auth, $companyId, 'accounting.reconcile');
        $this->requireCompanyPermission($auth, $companyId, 'accounting.post');
        $accountId = $this->positiveId($input['account_id'] ?? null, 'Counterpart Account');
        $label = trim((string)($input['label'] ?? ''));
        if ($label !== '' && mb_strlen($label) > 190) throw new InvalidArgumentException('Label must be 190 characters or fewer.');
        $this->pdo->beginTransaction();
        try {
            $lineQuery = $this->pdo->prepare(
                'SELECT l.id,l.statement_id,l.transaction_date,l.reference,l.description,l.amount,l.currency_code,s.state AS statement_state,s.company_id,s.journal_id,s.currency_code AS statement_currency,j.active AS journal_active,j.default_account_id,co.currency_code AS company_currency,cur.decimal_places ' .
                'FROM bank_statement_lines l JOIN bank_statements s ON s.id=l.statement_id JOIN accounting_journals j ON j.id=s.journal_id JOIN companies co ON co.id=s.company_id JOIN currencies cur ON cur.currency_code=s.currency_code ' .
                'WHERE l.id=:line AND l.statement_id=:statement FOR UPDATE'
            );
            $lineQuery->execute(['line' => $lineId, 'statement' => $statementId]);
            $line = $lineQuery->fetch();
            if (!$line || (int)$line['company_id'] !== $companyId || !(bool)$line['journal_active'] || $line['statement_state'] !== 'imported' || $line['currency_code'] !== $line['statement_currency'] || $line['statement_currency'] !== $line['company_currency']) {
                throw new InvalidArgumentException('Select an unmatched line from an open statement in the company currency.');
            }
            $this->assertUnmatched($lineId);
            $places = (int)$line['decimal_places'];
            $signedMinor = $this->minor((string)$line['amount'], $places, true, 'Statement Amount');
            if ($signedMinor === 0) throw new InvalidArgumentException('Statement amount must not be zero.');
            $counterpartQuery = $this->pdo->prepare(
                "SELECT id,code,name,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1 AND account_type NOT IN ('asset_cash','asset_receivable','liability_payable','off_balance')"
            );
            $counterpartQuery->execute(['id' => $accountId, 'company' => $companyId]);
            $counterpart = $counterpartQuery->fetch();
            $bankAccount = $this->account((int)$line['default_account_id'], $companyId);
            if (!$counterpart || $accountId === (int)$line['default_account_id']) throw new InvalidArgumentException('Choose an active counterpart account in this company, different from the journal Bank/Cash account.');
            if (!$bankAccount || !in_array($bankAccount['account_type'], ['asset_cash','asset_current'], true)) throw new InvalidArgumentException('The selected Bank/Cash journal needs an active Bank/Cash account.');
            $reference = $label !== '' ? $label : (trim((string)$line['reference']) ?: ('Statement line ' . $lineId));
            $date = (string)$line['transaction_date'];
            AccountingDateGuard::lockAndAssertOpen($this->pdo, $companyId, $date);
            $amountMinor = abs($signedMinor);
            $amount = $this->decimal($amountMinor, $places);
            $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
            $this->pdo->prepare(
                'INSERT INTO bank_statement_account_matches (company_id,journal_id,statement_line_id,account_id,reference,transaction_date,amount,currency_code,created_by,matched_at) VALUES (:company,:journal,:line,:account,:reference,:date,:amount,:currency,:actor,UTC_TIMESTAMP())'
            )->execute(['company' => $companyId, 'journal' => $line['journal_id'], 'line' => $lineId, 'account' => $accountId, 'reference' => $reference, 'date' => $date, 'amount' => $amount, 'currency' => $line['currency_code'], 'actor' => $user->id]);
            $matchId = (int)$this->pdo->lastInsertId();
            $moveName = sprintf('BSC%04d-%06d', (int)substr($date, 0, 4), $matchId);
            $moveReference = $reference . ' · ' . (string)$line['reference'];
            $this->pdo->prepare(
                "INSERT INTO accounting_moves (company_id,name,journal_id,move_type,reference_model,reference_id,reference,accounting_date,currency_code,state,total_debit,total_credit,created_by) VALUES (:company,:name,:journal,'bank_statement_account_match','bank_statement_account_match',:match_id,:reference,:date,:currency,'draft',:debit,:credit,:actor)"
            )->execute(['company' => $companyId, 'name' => $moveName, 'journal' => $line['journal_id'], 'match_id' => $matchId, 'reference' => mb_substr($moveReference, 0, 120), 'date' => $date, 'currency' => $line['currency_code'], 'debit' => $amount, 'credit' => $amount, 'actor' => $user->id]);
            $moveId = (int)$this->pdo->lastInsertId();
            $bankFirst = $signedMinor > 0;
            $firstAccount = $bankFirst ? (int)$line['default_account_id'] : $accountId;
            $secondAccount = $bankFirst ? $accountId : (int)$line['default_account_id'];
            $firstLabel = $bankFirst ? 'Bank Statement · ' . $reference : 'Statement Counterpart · ' . $reference;
            $secondLabel = $bankFirst ? 'Statement Counterpart · ' . $reference : 'Bank Statement · ' . $reference;
            $insertLine = $this->pdo->prepare('INSERT INTO accounting_move_lines (move_id,sequence,account_id,contact_id,label,debit,credit,currency_code,amount_currency) VALUES (:move,:sequence,:account,NULL,:label,:debit,:credit,:currency,:amount_currency)');
            $insertLine->execute(['move' => $moveId, 'sequence' => 10, 'account' => $firstAccount, 'label' => $firstLabel, 'debit' => $amount, 'credit' => '0', 'currency' => $line['currency_code'], 'amount_currency' => $amount]);
            $insertLine->execute(['move' => $moveId, 'sequence' => 20, 'account' => $secondAccount, 'label' => $secondLabel, 'debit' => '0', 'credit' => $amount, 'currency' => $line['currency_code'], 'amount_currency' => '-' . $amount]);
            $this->pdo->prepare("UPDATE accounting_moves SET state='posted',posted_by=:actor,posted_at=UTC_TIMESTAMP() WHERE id=:id AND state='draft'")->execute(['actor' => $user->id, 'id' => $moveId]);
            $this->pdo->prepare('UPDATE bank_statement_account_matches SET accounting_move_id=:move WHERE id=:id')->execute(['move' => $moveId, 'id' => $matchId]);
            $audit = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,NULL,:new,:ip)');
            $audit->execute(['company' => $companyId, 'user' => $user->id, 'event' => 'bank_statement_line_categorized', 'model' => 'bank_statement_account_match', 'record' => $matchId, 'new' => json_encode(['statement_id' => $statementId, 'statement_line_id' => $lineId, 'account_id' => $accountId, 'accounting_move_id' => $moveId, 'amount' => $amount, 'currency_code' => $line['currency_code'], 'date' => $date], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'ip' => $ip]);
            self::refreshStatementState($this->pdo, $statementId);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['statement' => $this->findHeader($statementId, $user, $auth), 'match_id' => $matchId, 'accounting_move_id' => $moveId];
    }

    public static function refreshStatementState(PDO $pdo, int $statementId): void
    {
        $pdo->prepare(
            "UPDATE bank_statements s SET state='reconciled',reconciled_at=UTC_TIMESTAMP() " .
            "WHERE s.id=:id AND s.state='imported' AND NOT EXISTS (" .
            'SELECT 1 FROM bank_statement_lines l LEFT JOIN bank_statement_matches cm ON cm.statement_line_id=l.id ' .
            'LEFT JOIN customer_credit_note_refund_matches crm ON crm.statement_line_id=l.id LEFT JOIN vendor_bank_statement_matches vm ON vm.statement_line_id=l.id LEFT JOIN vendor_credit_note_refund_matches vcrm ON vcrm.statement_line_id=l.id LEFT JOIN bank_statement_account_matches am ON am.statement_line_id=l.id ' .
            'WHERE l.statement_id=s.id AND cm.id IS NULL AND crm.id IS NULL AND vm.id IS NULL AND vcrm.id IS NULL AND am.id IS NULL)'
        )->execute(['id' => $statementId]);
    }

    private function findHeader(int $id, UserContext $user, Authorization $auth): array
    {
        if ($id < 1) throw new InvalidArgumentException('Bank Statement ID must be a positive integer.');
        $query = $this->pdo->prepare(
            'SELECT s.id,s.company_id,s.journal_id,s.name,s.source_file_name,s.statement_date_from,s.statement_date_to,s.opening_balance,s.closing_balance,s.currency_code,s.line_count,s.state,s.created_at,s.reconciled_at,j.code AS journal_code,j.name AS journal_name ' .
            'FROM bank_statements s JOIN accounting_journals j ON j.id=s.journal_id WHERE s.id=:id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $statement = $query->fetch();
        if (!$statement) throw new InvalidArgumentException('Bank Statement was not found.');
        $this->requireCompanyPermission($auth, (int)$statement['company_id'], 'accounting.read');
        $this->castStatement($statement);
        return $statement;
    }

    private function company(int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT c.id,c.name,c.currency_code,cur.decimal_places FROM companies c JOIN currencies cur ON cur.currency_code=c.currency_code WHERE c.id=:id AND c.active=1');
        $query->execute(['id' => $companyId]);
        $company = $query->fetch();
        if (!$company) throw new InvalidArgumentException('Company was not found or is archived.');
        $company['id'] = (int)$company['id'];
        $company['decimal_places'] = (int)$company['decimal_places'];
        return $company;
    }

    private function assertUnmatched(int $lineId): void
    {
        $query = $this->pdo->prepare('SELECT (EXISTS(SELECT 1 FROM bank_statement_matches WHERE statement_line_id=:customer_line) OR EXISTS(SELECT 1 FROM customer_credit_note_refund_matches WHERE statement_line_id=:customer_refund_line) OR EXISTS(SELECT 1 FROM vendor_bank_statement_matches WHERE statement_line_id=:vendor_line) OR EXISTS(SELECT 1 FROM vendor_credit_note_refund_matches WHERE statement_line_id=:vendor_refund_line) OR EXISTS(SELECT 1 FROM bank_statement_account_matches WHERE statement_line_id=:account_line))');
        $query->execute(['customer_line' => $lineId, 'customer_refund_line'=>$lineId, 'vendor_line' => $lineId, 'vendor_refund_line'=>$lineId, 'account_line' => $lineId]);
        if ((bool)$query->fetchColumn()) throw new InvalidArgumentException('This bank statement line is already reconciled.');
    }

    private function account(int $id, int $companyId): ?array
    {
        if ($id < 1) return null;
        $query = $this->pdo->prepare('SELECT id,code,name,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');
        $query->execute(['id' => $id, 'company' => $companyId]);
        return $query->fetch() ?: null;
    }

    private function castStatement(array &$statement): void
    {
        if (isset($statement['id'])) $statement['id'] = (int)$statement['id'];
        if (isset($statement['company_id'])) $statement['company_id'] = (int)$statement['company_id'];
        if (isset($statement['journal_id'])) $statement['journal_id'] = (int)$statement['journal_id'];
        if (isset($statement['line_count'])) $statement['line_count'] = (int)$statement['line_count'];
        if (isset($statement['unmatched_count'])) $statement['unmatched_count'] = (int)$statement['unmatched_count'];
        if (isset($statement['decimal_places'])) $statement['decimal_places'] = (int)$statement['decimal_places'];
    }

    private function requireCompanyPermission(Authorization $auth, int $companyId, string $permission): void
    {
        if (!$auth->allows($permission) || !$auth->allowsForCompany($permission, $companyId)) {
            throw new AccessDeniedException('You do not have permission to access bank statements for this company.');
        }
    }

    private function companyId(mixed $raw, Authorization $auth): int
    {
        $id = $this->positiveId($raw, 'Company');
        if (!$auth->canAccessCompany($id)) throw new AccessDeniedException('This company is outside your allowed companies.');
        return $id;
    }

    private function positiveId(mixed $raw, string $label): int
    {
        if (!is_int($raw) && !is_string($raw)) throw new InvalidArgumentException($label . ' must be a positive ID.');
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' must be a positive ID.');
        return $id;
    }

    private function minor(string $amount, int $places, bool $allowNegative, string $label): int
    {
        if ($places < 0 || $places > 4 || preg_match('/^(-?)(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D', trim($amount), $match) !== 1) {
            throw new InvalidArgumentException($label . ' must be a plain decimal amount in the company currency.');
        }
        $negative = $match[1] === '-';
        if ($negative && !$allowNegative) throw new InvalidArgumentException($label . ' cannot be negative.');
        $fraction = $match[3] ?? '';
        if (strlen($fraction) > $places && trim(substr($fraction, $places), '0') !== '') {
            throw new InvalidArgumentException($label . ' has more decimal places than the company currency allows.');
        }
        $factor = 10 ** $places;
        $fractionMinor = $places === 0 ? 0 : (int)str_pad(substr($fraction, 0, $places), $places, '0');
        $value = (int)$match[2] * $factor + $fractionMinor;
        return $negative ? -$value : $value;
    }

    private function decimal(int $minor, int $places): string
    {
        $factor = 10 ** $places;
        $whole = intdiv(abs($minor), $factor);
        $fraction = $places ? '.' . str_pad((string)(abs($minor) % $factor), $places, '0', STR_PAD_LEFT) : '';
        return ($minor < 0 ? '-' : '') . $whole . $fraction;
    }
}
