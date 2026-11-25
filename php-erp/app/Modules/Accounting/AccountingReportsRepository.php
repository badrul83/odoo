<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class AccountingReportsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(mixed $rawCompanyId, UserContext $user, Authorization $auth): array
    {
        if (!$auth->allows('accounting.read')) throw new AccessDeniedException('You do not have permission to read Accounting reports.');
        $companyId = $this->companyId($rawCompanyId ?? $user->defaultCompanyId, $auth);
        if (!$auth->allowsForCompany('accounting.read', $companyId)) throw new AccessDeniedException('You do not have permission to read Accounting reports for this company.');
        $company = $this->pdo->prepare('SELECT c.id,c.name,c.currency_code,cur.decimal_places FROM companies c JOIN currencies cur ON cur.currency_code=c.currency_code WHERE c.id=:id AND c.active=1');
        $company->execute(['id'=>$companyId]);
        $companyRow = $company->fetch();
        if (!$companyRow) throw new InvalidArgumentException('Company was not found or is archived.');
        $accounts = $this->pdo->prepare('SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company AND active=1 ORDER BY code,id');
        $accounts->execute(['company'=>$companyId]);
        return ['company'=>['id'=>(int)$companyRow['id'],'name'=>$companyRow['name'],'currency_code'=>$companyRow['currency_code'],'decimal_places'=>(int)$companyRow['decimal_places']],'accounts'=>$accounts->fetchAll()];
    }

    public function report(array $filters, UserContext $user, Authorization $auth): array
    {
        if (!$auth->allows('accounting.read')) throw new AccessDeniedException('You do not have permission to read Accounting reports.');
        $unknown=array_diff(array_keys($filters),['company_id','report','date_from','date_to','account_id','limit','offset']);
        if ($unknown) throw new InvalidArgumentException('Unsupported Accounting report filters: '.implode(', ',$unknown));
        $companyId=$this->companyId($filters['company_id']??$user->defaultCompanyId,$auth);
        if (!$auth->allowsForCompany('accounting.read', $companyId)) throw new AccessDeniedException('You do not have permission to read Accounting reports for this company.');
        $report=(string)($filters['report']??'');
        if(!in_array($report,['trial_balance','general_ledger','profit_loss','balance_sheet'],true))throw new InvalidArgumentException('Select a valid Accounting report.');
        $to=$this->date($filters['date_to']??null,'As-of date');
        $from=$filters['date_from']??null;
        if(in_array($report,['general_ledger','profit_loss'],true)){
            $from=$this->date($from,'Start date');
            if($from>$to)throw new InvalidArgumentException('Start date must be on or before the end date.');
        }elseif($from!==null&&$from!=='')$from=$this->date($from,'Start date');
        $limit=max(1,min(100,(int)($filters['limit']??100)));
        $offset=max(0,min(1000000,(int)($filters['offset']??0)));
        $company=$this->companyDetails($companyId);
        $result=match($report){
            'trial_balance'=>$this->trialBalance($companyId,$to,$limit,$offset,(int)$company['decimal_places']),
            'general_ledger'=>$this->generalLedger($companyId,$from,$to,$filters['account_id']??null,$limit,$offset,(int)$company['decimal_places']),
            'profit_loss'=>$this->profitLoss($companyId,$from,$to,$limit,$offset,(int)$company['decimal_places']),
            'balance_sheet'=>$this->balanceSheet($companyId,$to,$limit,$offset,(int)$company['decimal_places']),
        };
        return ['report'=>$report,'company'=>$company,'date_from'=>$from,'date_to'=>$to]+$result;
    }

    public function move(int $id, Authorization $auth): ?array
    {
        if (!$auth->allows('accounting.read')) throw new AccessDeniedException('You do not have permission to read Accounting entries.');
        $query = $this->pdo->prepare(
            'SELECT m.id,m.company_id,co.name AS company_name,m.name,m.journal_id,j.code AS journal_code,j.name AS journal_name,' .
            'm.move_type,m.reference_model,m.reference_id,m.reference,m.accounting_date,m.currency_code,m.state,m.total_debit,m.total_credit,' .
            'm.created_by,creator.name AS created_by_name,m.posted_by,poster.name AS posted_by_name,m.posted_at,m.created_at ' .
            'FROM accounting_moves m JOIN companies co ON co.id=m.company_id JOIN accounting_journals j ON j.id=m.journal_id ' .
            'JOIN users cu ON cu.id=m.created_by JOIN contacts creator ON creator.id=cu.contact_id ' .
            'LEFT JOIN users pu ON pu.id=m.posted_by LEFT JOIN contacts poster ON poster.id=pu.contact_id WHERE m.id=:id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $move = $query->fetch();
        if (!$move || !$auth->canAccessCompany((int)$move['company_id'])) return null;
        if (!$auth->allowsForCompany('accounting.read', (int)$move['company_id'])) throw new AccessDeniedException('You do not have permission to read Accounting entries for this company.');
        $lines = $this->pdo->prepare(
            'SELECT ml.id,ml.sequence,ml.account_id,a.code AS account_code,a.name AS account_name,ml.contact_id,c.name AS contact_name,' .
            'ml.label,ml.debit,ml.credit,ml.currency_code,ml.amount_currency ' .
            'FROM accounting_move_lines ml JOIN accounting_accounts a ON a.id=ml.account_id ' .
            'LEFT JOIN contacts c ON c.id=ml.contact_id WHERE ml.move_id=:move AND a.company_id=:company ORDER BY ml.sequence,ml.id'
        );
        $lines->execute(['move' => $id, 'company' => $move['company_id']]);
        $move['lines'] = $lines->fetchAll();
        [$move['source_record_model'], $move['source_record_id'], $move['source_record_line_id']] = $this->sourceRecord((string)$move['reference_model'], (int)$move['reference_id'], (int)$move['company_id']);
        foreach (['id','company_id','journal_id','reference_id','created_by'] as $field) $move[$field] = (int)$move[$field];
        if ($move['source_record_id'] !== null) $move['source_record_id'] = (int)$move['source_record_id'];
        if ($move['source_record_line_id'] !== null) $move['source_record_line_id'] = (int)$move['source_record_line_id'];
        if ($move['posted_by'] !== null) $move['posted_by'] = (int)$move['posted_by'];
        foreach ($move['lines'] as &$line) {
            foreach (['id','sequence','account_id'] as $field) $line[$field] = (int)$line[$field];
            if ($line['contact_id'] !== null) $line['contact_id'] = (int)$line['contact_id'];
        }
        unset($line);
        return $move;
    }

    /** @return array{0:?string,1:?int,2:?int} */
    private function sourceRecord(string $referenceModel, int $referenceId, int $companyId): array
    {
        if ($referenceModel === 'bank_statement_account_match') {
            $query = $this->pdo->prepare(
                'SELECT s.id AS statement_id,l.id AS statement_line_id FROM bank_statement_account_matches a ' .
                'JOIN bank_statement_lines l ON l.id=a.statement_line_id ' .
                'JOIN bank_statements s ON s.id=l.statement_id AND s.company_id=a.company_id ' .
                'WHERE a.id=:id AND a.company_id=:company'
            );
            $query->execute(['id' => $referenceId, 'company' => $companyId]);
            $source = $query->fetch(PDO::FETCH_ASSOC);
            return $source
                ? ['bank_statement', (int)$source['statement_id'], (int)$source['statement_line_id']]
                : [null, null, null];
        }
        if ($referenceModel === 'customer_credit_note_refund_match') {
            $query=$this->pdo->prepare('SELECT s.id AS statement_id,l.id AS statement_line_id FROM customer_credit_note_refund_matches m JOIN bank_statement_lines l ON l.id=m.statement_line_id JOIN bank_statements s ON s.id=l.statement_id AND s.company_id=m.company_id WHERE m.id=:id AND m.company_id=:company');
            $query->execute(['id'=>$referenceId,'company'=>$companyId]);$source=$query->fetch(PDO::FETCH_ASSOC);
            if($source)return ['bank_statement',(int)$source['statement_id'],(int)$source['statement_line_id']];
        }
        if ($referenceModel === 'vendor_credit_note_refund_match') {
            $query=$this->pdo->prepare('SELECT s.id AS statement_id,l.id AS statement_line_id FROM vendor_credit_note_refund_matches m JOIN bank_statement_lines l ON l.id=m.statement_line_id JOIN bank_statements s ON s.id=l.statement_id AND s.company_id=m.company_id WHERE m.id=:id AND m.company_id=:company');
            $query->execute(['id'=>$referenceId,'company'=>$companyId]);$source=$query->fetch(PDO::FETCH_ASSOC);
            if($source)return ['bank_statement',(int)$source['statement_id'],(int)$source['statement_line_id']];
        }
        $targetModel = $referenceModel;
        $sql = match ($referenceModel) {
            'customer_invoice' => 'SELECT id FROM customer_invoices WHERE id=:id AND company_id=:company',
            'vendor_bill' => 'SELECT id FROM vendor_bills WHERE id=:id AND company_id=:company',
            'vendor_credit_note' => 'SELECT b.id FROM vendor_credit_notes cn JOIN vendor_bills b ON b.id=cn.vendor_bill_id AND b.company_id=cn.company_id WHERE cn.id=:id AND cn.company_id=:company',
            'vendor_credit_note_refund' => 'SELECT b.id FROM vendor_credit_note_refunds r JOIN vendor_credit_notes cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id JOIN vendor_bills b ON b.id=cn.vendor_bill_id AND b.company_id=cn.company_id WHERE r.id=:id AND r.company_id=:company',
            'inventory_cycle_count' => 'SELECT id FROM inventory_cycle_counts WHERE id=:id AND company_id=:company',
            'inventory_valuation_closing' => 'SELECT id FROM inventory_valuation_closings WHERE id=:id AND company_id=:company',
            'manufacturing_order' => 'SELECT id FROM manufacturing_orders WHERE id=:id AND company_id=:company',
            'customer_payment' => 'SELECT i.id FROM customer_payments p JOIN customer_invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id WHERE p.id=:id AND p.company_id=:company',
            'customer_credit_note_refund' => 'SELECT cn.id FROM customer_credit_note_refunds r JOIN customer_invoices cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id WHERE r.id=:id AND r.company_id=:company',
            'vendor_payment' => 'SELECT b.id FROM vendor_payments p JOIN vendor_bills b ON b.id=p.vendor_bill_id AND b.company_id=p.company_id WHERE p.id=:id AND p.company_id=:company',
            'bank_statement_match' => 'SELECT i.id FROM bank_statement_matches s JOIN customer_payments p ON p.id=s.payment_id AND p.company_id=s.company_id JOIN customer_invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id WHERE s.id=:id AND s.company_id=:company',
            'customer_credit_note_refund_match' => 'SELECT cn.id FROM customer_credit_note_refund_matches m JOIN customer_credit_note_refunds r ON r.id=m.refund_id AND r.company_id=m.company_id JOIN customer_invoices cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id WHERE m.id=:id AND m.company_id=:company',
            'vendor_credit_note_refund_match' => 'SELECT b.id FROM vendor_credit_note_refund_matches m JOIN vendor_credit_note_refunds r ON r.id=m.refund_id AND r.company_id=m.company_id JOIN vendor_credit_notes cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id JOIN vendor_bills b ON b.id=cn.vendor_bill_id AND b.company_id=cn.company_id WHERE m.id=:id AND m.company_id=:company',
            'vendor_bank_statement_match' => 'SELECT b.id FROM vendor_bank_statement_matches s JOIN vendor_payments p ON p.id=s.payment_id AND p.company_id=s.company_id JOIN vendor_bills b ON b.id=p.vendor_bill_id AND b.company_id=p.company_id WHERE s.id=:id AND s.company_id=:company',
            default => null,
        };
        if ($sql === null || $referenceId < 1) return [null, null, null];
        if (in_array($referenceModel, ['customer_payment','bank_statement_match','customer_credit_note_refund','customer_credit_note_refund_match'], true)) $targetModel = 'customer_invoice';
        if (in_array($referenceModel, ['vendor_payment','vendor_bank_statement_match','vendor_credit_note','vendor_credit_note_refund','vendor_credit_note_refund_match'], true)) $targetModel = 'vendor_bill';
        $query = $this->pdo->prepare($sql);
        $query->execute(['id' => $referenceId, 'company' => $companyId]);
        $targetId = $query->fetchColumn();
        return $targetId === false ? [null, null, null] : [$targetModel, (int)$targetId, null];
    }

    private function trialBalance(int $company,string $asOf,int $limit,int $offset,int $places):array
    {
        $query=$this->pdo->prepare("SELECT a.id,a.code,a.name,a.account_type,COALESCE(SUM(CASE WHEN am.id IS NULL THEN 0 ELSE ml.debit END),0) AS debit_total,COALESCE(SUM(CASE WHEN am.id IS NULL THEN 0 ELSE ml.credit END),0) AS credit_total,COALESCE(SUM(CASE WHEN am.id IS NULL THEN 0 ELSE ml.debit-ml.credit END),0) AS net_balance FROM accounting_accounts a LEFT JOIN accounting_move_lines ml ON ml.account_id=a.id LEFT JOIN accounting_moves am ON am.id=ml.move_id AND am.company_id=a.company_id AND am.state='posted' AND am.accounting_date<=:asof WHERE a.company_id=:company AND a.active=1 GROUP BY a.id,a.code,a.name,a.account_type ORDER BY a.code,a.id");
        $query->execute(['asof'=>$asOf,'company'=>$company]);$all=[];$debitTotal='0';$creditTotal='0';
        foreach($query->fetchAll() as $row){$debit=$this->toMinor((string)$row['debit_total'],$places);$credit=$this->toMinor((string)$row['credit_total'],$places);if($debit==='0'&&$credit==='0')continue;$net=$this->addMinor($debit,$this->negateMinor($credit));$row['debit_balance']=$net[0]==='-'?$this->fromMinor('0',$places):$this->fromMinor($net,$places);$row['credit_balance']=$net[0]==='-'?$this->fromMinor($this->negateMinor($net),$places):$this->fromMinor('0',$places);$row['debit_total']=(string)$row['debit_total'];$row['credit_total']=(string)$row['credit_total'];$row['id']=(int)$row['id'];$all[]=$row;$debitTotal=$this->addMinor($debitTotal,$net[0]==='-'?'0':$net);$creditTotal=$this->addMinor($creditTotal,$net[0]==='-'?$this->negateMinor($net):'0');}
        return ['records'=>array_slice($all,$offset,$limit),'total'=>count($all),'limit'=>$limit,'offset'=>$offset,'totals'=>['debit_balance'=>$this->fromMinor($debitTotal,$places),'credit_balance'=>$this->fromMinor($creditTotal,$places)]];
    }

    private function generalLedger(int $company,string $from,string $to,mixed $rawAccount,int $limit,int $offset,int $places):array
    {
        $accountId=$this->positiveId($rawAccount,'Account');
        $account=$this->pdo->prepare('SELECT id,code,name,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');$account->execute(['id'=>$accountId,'company'=>$company]);$accountRow=$account->fetch();
        if(!$accountRow)throw new InvalidArgumentException('Select an active account in the selected company.');
        $opening=$this->pdo->prepare("SELECT COALESCE(SUM(ml.debit-ml.credit),0) FROM accounting_move_lines ml JOIN accounting_moves am ON am.id=ml.move_id AND am.company_id=:company AND am.state='posted' WHERE ml.account_id=:account AND am.accounting_date<:date_from");$opening->execute(['company'=>$company,'account'=>$accountId,'date_from'=>$from]);$openingMinor=$this->toMinor((string)$opening->fetchColumn(),$places);
        $where="am.company_id=:company AND am.state='posted' AND ml.account_id=:account AND am.accounting_date BETWEEN :date_from AND :date_to";
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM accounting_move_lines ml JOIN accounting_moves am ON am.id=ml.move_id WHERE '.$where);$count->execute(['company'=>$company,'account'=>$accountId,'date_from'=>$from,'date_to'=>$to]);$total=(int)$count->fetchColumn();
        $priorMinor='0';
        if($offset>0){$prior=$this->pdo->prepare('SELECT COALESCE(SUM(page.debit-page.credit),0) FROM (SELECT ml.debit,ml.credit FROM accounting_move_lines ml JOIN accounting_moves am ON am.id=ml.move_id WHERE '.$where.' ORDER BY am.accounting_date,am.id,ml.sequence,ml.id LIMIT :offset) page');$prior->bindValue(':company',$company,PDO::PARAM_INT);$prior->bindValue(':account',$accountId,PDO::PARAM_INT);$prior->bindValue(':date_from',$from);$prior->bindValue(':date_to',$to);$prior->bindValue(':offset',$offset,PDO::PARAM_INT);$prior->execute();$priorMinor=$this->toMinor((string)$prior->fetchColumn(),$places);}
        $pageStart=$this->addMinor($openingMinor,$priorMinor);
        $lines=$this->pdo->prepare('SELECT ml.id,ml.sequence,ml.label,ml.debit,ml.credit,ml.contact_id,c.name AS contact_name,am.id AS move_id,am.name AS move_name,am.move_type,am.reference,am.accounting_date,j.code AS journal_code,j.name AS journal_name FROM accounting_move_lines ml JOIN accounting_moves am ON am.id=ml.move_id JOIN accounting_journals j ON j.id=am.journal_id LEFT JOIN contacts c ON c.id=ml.contact_id WHERE '.$where.' ORDER BY am.accounting_date,am.id,ml.sequence,ml.id LIMIT :limit OFFSET :offset');
        $lines->bindValue(':company',$company,PDO::PARAM_INT);$lines->bindValue(':account',$accountId,PDO::PARAM_INT);$lines->bindValue(':date_from',$from);$lines->bindValue(':date_to',$to);$lines->bindValue(':limit',$limit,PDO::PARAM_INT);$lines->bindValue(':offset',$offset,PDO::PARAM_INT);$lines->execute();$records=$lines->fetchAll();$balance=$pageStart;
        foreach($records as &$line){$line['debit']=(string)$line['debit'];$line['credit']=(string)$line['credit'];$balance=$this->addMinor($balance,$this->addMinor($this->toMinor($line['debit'],$places),$this->negateMinor($this->toMinor($line['credit'],$places))));$line['running_balance']=$this->fromMinor($balance,$places);$line['id']=(int)$line['id'];$line['move_id']=(int)$line['move_id'];if($line['contact_id']!==null)$line['contact_id']=(int)$line['contact_id'];}
        unset($line);
        $period=$this->pdo->prepare('SELECT COALESCE(SUM(ml.debit),0) AS debit_total,COALESCE(SUM(ml.credit),0) AS credit_total FROM accounting_move_lines ml JOIN accounting_moves am ON am.id=ml.move_id WHERE '.$where);$period->execute(['company'=>$company,'account'=>$accountId,'date_from'=>$from,'date_to'=>$to]);$periodTotals=$period->fetch();$periodNet=$this->addMinor($this->toMinor((string)$periodTotals['debit_total'],$places),$this->negateMinor($this->toMinor((string)$periodTotals['credit_total'],$places)));
        $closing=$this->addMinor($openingMinor,$periodNet);
        return ['account'=>$accountRow,'records'=>$records,'total'=>$total,'limit'=>$limit,'offset'=>$offset,'totals'=>['opening_balance'=>$this->fromMinor($openingMinor,$places),'period_debit'=>$this->fromMinor($this->toMinor((string)$periodTotals['debit_total'],$places),$places),'period_credit'=>$this->fromMinor($this->toMinor((string)$periodTotals['credit_total'],$places),$places),'closing_balance'=>$this->fromMinor($closing,$places),'page_balance'=>$this->fromMinor($balance,$places)]];
    }

    private function profitLoss(int $company,string $from,string $to,int $limit,int $offset,int $places):array
    {
        $query=$this->pdo->prepare("SELECT a.id,a.code,a.name,a.account_type,COALESCE(SUM(ml.debit),0) AS debit_total,COALESCE(SUM(ml.credit),0) AS credit_total FROM accounting_accounts a JOIN accounting_move_lines ml ON ml.account_id=a.id JOIN accounting_moves am ON am.id=ml.move_id AND am.company_id=a.company_id AND am.state='posted' AND am.accounting_date BETWEEN :date_from AND :date_to WHERE a.company_id=:company AND a.active=1 AND a.account_type IN ('income','income_other','expense','expense_other','expense_depreciation','expense_direct_cost') GROUP BY a.id,a.code,a.name,a.account_type ORDER BY a.account_type,a.code,a.id");$query->execute(['date_from'=>$from,'date_to'=>$to,'company'=>$company]);$all=[];$income='0';$expense='0';
        foreach($query->fetchAll() as $row){$debit=$this->toMinor((string)$row['debit_total'],$places);$credit=$this->toMinor((string)$row['credit_total'],$places);$amount=in_array($row['account_type'],['income','income_other'],true)?$this->addMinor($credit,$this->negateMinor($debit)):$this->addMinor($debit,$this->negateMinor($credit));$row['amount']=$this->fromMinor($amount,$places);$row['id']=(int)$row['id'];$row['debit_total']=(string)$row['debit_total'];$row['credit_total']=(string)$row['credit_total'];$all[]=$row;if(in_array($row['account_type'],['income','income_other'],true))$income=$this->addMinor($income,$amount);else$expense=$this->addMinor($expense,$amount);}
        $net=$this->addMinor($income,$this->negateMinor($expense));
        return ['records'=>array_slice($all,$offset,$limit),'total'=>count($all),'limit'=>$limit,'offset'=>$offset,'totals'=>['income'=>$this->fromMinor($income,$places),'expenses'=>$this->fromMinor($expense,$places),'net_profit'=>$this->fromMinor($net,$places)]];
    }

    private function balanceSheet(int $company,string $asOf,int $limit,int $offset,int $places):array
    {
        $query=$this->pdo->prepare("SELECT a.id,a.code,a.name,a.account_type,COALESCE(SUM(CASE WHEN am.id IS NULL THEN 0 ELSE ml.debit END),0) AS debit_total,COALESCE(SUM(CASE WHEN am.id IS NULL THEN 0 ELSE ml.credit END),0) AS credit_total FROM accounting_accounts a LEFT JOIN accounting_move_lines ml ON ml.account_id=a.id LEFT JOIN accounting_moves am ON am.id=ml.move_id AND am.company_id=a.company_id AND am.state='posted' AND am.accounting_date<=:asof WHERE a.company_id=:company AND a.active=1 AND a.account_type IN ('asset_receivable','asset_cash','asset_current','asset_fixed','asset_non_current','asset_prepayments','liability_payable','liability_credit_card','liability_current','liability_non_current','equity','equity_unaffected','equity_retained') GROUP BY a.id,a.code,a.name,a.account_type ORDER BY CASE WHEN a.account_type LIKE 'asset%' THEN 1 WHEN a.account_type LIKE 'liability%' THEN 2 ELSE 3 END,a.code,a.id");$query->execute(['asof'=>$asOf,'company'=>$company]);$all=[];$assets='0';$liabilities='0';$equity='0';
        foreach($query->fetchAll() as $row){$debit=$this->toMinor((string)$row['debit_total'],$places);$credit=$this->toMinor((string)$row['credit_total'],$places);$isAsset=str_starts_with($row['account_type'],'asset_');$amount=$isAsset?$this->addMinor($debit,$this->negateMinor($credit)):$this->addMinor($credit,$this->negateMinor($debit));if($debit==='0'&&$credit==='0')continue;$row['section']=$isAsset?'Assets':(str_starts_with($row['account_type'],'liability_')?'Liabilities':'Equity');$row['balance']=$this->fromMinor($amount,$places);$row['id']=(int)$row['id'];$all[]=$row;if($isAsset)$assets=$this->addMinor($assets,$amount);elseif($row['section']==='Liabilities')$liabilities=$this->addMinor($liabilities,$amount);else$equity=$this->addMinor($equity,$amount);}
        $earningsQuery=$this->pdo->prepare("SELECT COALESCE(SUM(ml.credit-ml.debit),0) FROM accounting_move_lines ml JOIN accounting_moves am ON am.id=ml.move_id AND am.company_id=:company AND am.state='posted' AND am.accounting_date<=:asof JOIN accounting_accounts a ON a.id=ml.account_id AND a.company_id=am.company_id WHERE a.account_type IN ('income','income_other','expense','expense_other','expense_depreciation','expense_direct_cost')");$earningsQuery->execute(['company'=>$company,'asof'=>$asOf]);$earnings=$this->toMinor((string)$earningsQuery->fetchColumn(),$places);
        if($earnings!=='0'){$all[]=['id'=>null,'code'=>'','name'=>'Unclosed earnings through '.$asOf,'account_type'=>'current_earnings','section'=>'Equity','debit_total'=>'0','credit_total'=>'0','balance'=>$this->fromMinor($earnings,$places)];$equity=$this->addMinor($equity,$earnings);}
        $all=array_values(array_filter($all,static fn(array $row):bool=>$row['id']===null||$row['balance']!=='0'));
        return ['records'=>array_slice($all,$offset,$limit),'total'=>count($all),'limit'=>$limit,'offset'=>$offset,'totals'=>['assets'=>$this->fromMinor($assets,$places),'liabilities'=>$this->fromMinor($liabilities,$places),'equity_with_unclosed_earnings'=>$this->fromMinor($equity,$places),'balance_difference'=>$this->fromMinor($this->addMinor($assets,$this->negateMinor($this->addMinor($liabilities,$equity))),$places)]];
    }

    private function companyDetails(int $companyId):array{$q=$this->pdo->prepare('SELECT c.id,c.name,c.currency_code,cur.decimal_places FROM companies c JOIN currencies cur ON cur.currency_code=c.currency_code WHERE c.id=:id AND c.active=1');$q->execute(['id'=>$companyId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Company was not found or is archived.');return['id'=>(int)$row['id'],'name'=>$row['name'],'currency_code'=>$row['currency_code'],'decimal_places'=>(int)$row['decimal_places']];}
    private function companyId(mixed $raw,Authorization $auth):int{$id=$this->positiveId($raw,'Company');if(!$auth->canAccessCompany($id))throw new InvalidArgumentException('Company is outside your allowed company scope.');$q=$this->pdo->prepare('SELECT 1 FROM companies WHERE id=:id AND active=1');$q->execute(['id'=>$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Company was not found or is archived.');return$id;}
    private function positiveId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive integer.');return$id;}
    private function date(mixed $value,string $label):string{$raw=is_string($value)?$value:'';$date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);if(!$date||$date->format('Y-m-d')!==$raw)throw new InvalidArgumentException($label.' must be a valid YYYY-MM-DD date.');return$raw;}
    private function toMinor(string $value,int $places):string
    {
        if(!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D',$value,$m))throw new InvalidArgumentException('An Accounting amount was not a decimal value.');
        $whole=ltrim($m[2],'0');$whole=$whole===''?'0':$whole;$fraction=$m[3]??'';$scaled=$whole.str_pad(substr($fraction,0,$places),$places,'0');$scaled=ltrim($scaled,'0');$scaled=$scaled===''?'0':$scaled;
        if(isset($fraction[$places])&&$fraction[$places]>='5')$scaled=$this->addAbs($scaled,'1');
        return $scaled!=='0'&&($m[1]??'')==='-'?'-'.$scaled:$scaled;
    }
    private function fromMinor(string $minor,int $places):string
    {
        $negative=str_starts_with($minor,'-');$digits=ltrim($negative?substr($minor,1):$minor,'0');$digits=$digits===''?'0':$digits;
        if($places===0)return($negative&&$digits!=='0'?'-':'').$digits;
        $digits=str_pad($digits,$places+1,'0',STR_PAD_LEFT);$whole=substr($digits,0,-$places);$fraction=substr($digits,-$places);
        return($negative&&$digits!=='0'?'-':'').$whole.'.'.$fraction;
    }
    private function addMinor(string $left,string $right):string
    {
        $leftNegative=str_starts_with($left,'-');$rightNegative=str_starts_with($right,'-');$a=ltrim($leftNegative?substr($left,1):$left,'0');$b=ltrim($rightNegative?substr($right,1):$right,'0');$a=$a===''?'0':$a;$b=$b===''?'0':$b;
        if($leftNegative===$rightNegative){$sum=$this->addAbs($a,$b);return$leftNegative&&$sum!=='0'?'-'.$sum:$sum;}
        $compare=$this->compareAbs($a,$b);if($compare===0)return'0';if($compare>0){$difference=$this->subtractAbs($a,$b);return$leftNegative?'-'.$difference:$difference;}$difference=$this->subtractAbs($b,$a);return$rightNegative?'-'.$difference:$difference;
    }
    private function negateMinor(string $value):string{return$value==='0'?'0':(str_starts_with($value,'-')?substr($value,1):'-'.$value);}
    private function addAbs(string $a,string $b):string{$i=strlen($a)-1;$j=strlen($b)-1;$carry=0;$out='';while($i>=0||$j>=0||$carry){$digit=($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0)+$carry;$out=($digit%10).$out;$carry=intdiv($digit,10);}return$out;}
    private function compareAbs(string $a,string $b):int{$a=ltrim($a,'0')?:'0';$b=ltrim($b,'0')?:'0';return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private function subtractAbs(string $a,string $b):string{$i=strlen($a)-1;$j=strlen($b)-1;$borrow=0;$out='';while($i>=0){$digit=(int)$a[$i--]-$borrow-($j>=0?(int)$b[$j--]:0);$borrow=0;if($digit<0){$digit+=10;$borrow=1;}$out=$digit.$out;}return ltrim($out,'0')?:'0';}
}
