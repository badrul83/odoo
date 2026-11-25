<?php
declare(strict_types=1);

namespace Erp\Modules\Accounting;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class ManualJournalEntryRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(mixed $rawCompany, UserContext $user, Authorization $auth): array
    {
        $this->require($auth, 'accounting.read', 'read manual journal entries');
        $companyId = $this->companyId($rawCompany ?? $user->defaultCompanyId, $auth);
        $company = $this->company($companyId);
        $journals = $this->pdo->prepare("SELECT id,code,name FROM accounting_journals WHERE company_id=:company AND active=1 AND journal_type='general' ORDER BY code,id");
        $journals->execute(['company' => $companyId]);
        $accounts = $this->pdo->prepare('SELECT id,code,name,account_type FROM accounting_accounts WHERE company_id=:company AND active=1 ORDER BY code,id');
        $accounts->execute(['company' => $companyId]);
        $contacts = [];
        if ($auth->allows('contacts.read')) {
            $query = $this->pdo->prepare('SELECT id,name,company_id FROM contacts WHERE active=1 AND (company_id IS NULL OR company_id=:company) ORDER BY name,id LIMIT 1000');
            $query->execute(['company' => $companyId]);
            $contacts = $query->fetchAll();
            foreach ($contacts as &$contact) {
                $contact['id'] = (int)$contact['id'];
                $contact['company_id'] = $contact['company_id'] === null ? null : (int)$contact['company_id'];
            }
            unset($contact);
        }
        return ['company' => $company, 'journals' => $journals->fetchAll(), 'accounts' => $accounts->fetchAll(), 'contacts' => $contacts];
    }

    public function search(array $filters, UserContext $user, Authorization $auth): array
    {
        $this->require($auth, 'accounting.read', 'read manual journal entries');
        $unknown = array_diff(array_keys($filters), ['company_id','partner_contact_id','state','q','limit','offset']);
        if ($unknown) throw new InvalidArgumentException('Unsupported journal entry filters: '.implode(', ', $unknown));
        $companyId = $this->companyId($filters['company_id'] ?? $user->defaultCompanyId, $auth);
        $where = ['e.company_id=:company']; $params = ['company' => $companyId];
        if (array_key_exists('partner_contact_id', $filters)) {
            $contactId = $this->positiveId($filters['partner_contact_id'], 'Contact');
            $contact = $this->pdo->prepare('SELECT id FROM contacts WHERE id=:id AND (company_id IS NULL OR company_id=:company)');
            $contact->execute(['id' => $contactId, 'company' => $companyId]);
            if (!$contact->fetchColumn()) throw new InvalidArgumentException('Contact is not available to the selected company.');
            $where[] = 'EXISTS (SELECT 1 FROM accounting_move_lines contact_line WHERE contact_line.move_id=m.id AND contact_line.contact_id=:partner_contact)';
            $params['partner_contact'] = $contactId;
        }
        $state = (string)($filters['state'] ?? 'all');
        if (!in_array($state, ['all','draft','posted'], true)) throw new InvalidArgumentException('Select a valid journal entry state.');
        if ($state !== 'all') { $where[] = 'm.state=:state'; $params['state'] = $state; }
        $text = trim((string)($filters['q'] ?? ''));
        if (mb_strlen($text) > 120) throw new InvalidArgumentException('Search text must be 120 characters or fewer.');
        if ($text !== '') {
            $like = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], $text).'%';
            $where[] = '(e.name LIKE :name OR e.reference LIKE :reference OR m.name LIKE :move_name OR m.reference LIKE :move_reference OR e.narration LIKE :narration)';
            $params += ['name'=>$like,'reference'=>$like,'move_name'=>$like,'move_reference'=>$like,'narration'=>$like];
        }
        $clause = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM accounting_manual_journal_entries e JOIN accounting_moves m ON m.id=e.accounting_move_id WHERE '.$clause);
        $count->execute($params);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $query = $this->pdo->prepare('SELECT e.id,e.company_id,e.name,e.reference,e.narration,e.reversal_of_entry_id,EXISTS(SELECT 1 FROM accounting_manual_journal_entries rev WHERE rev.reversal_of_entry_id=e.id) AS has_reversal,m.id AS move_id,m.name AS move_name,m.journal_id,j.code AS journal_code,j.name AS journal_name,m.accounting_date,m.currency_code,m.state,m.total_debit,m.total_credit,m.posted_at,m.posted_by FROM accounting_manual_journal_entries e JOIN accounting_moves m ON m.id=e.accounting_move_id JOIN accounting_journals j ON j.id=m.journal_id WHERE '.$clause.' ORDER BY m.accounting_date DESC,e.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key=>$value) $query->bindValue(':'.$key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT); $query->bindValue(':offset', $offset, PDO::PARAM_INT); $query->execute();
        return ['records'=>$this->castEntries($query->fetchAll()),'total'=>(int)$count->fetchColumn(),'company_id'=>$companyId,'limit'=>$limit,'offset'=>$offset];
    }

    public function find(int $id, UserContext $user, Authorization $auth): ?array
    {
        $this->require($auth, 'accounting.read', 'read manual journal entries');
        $entry = $this->entryQuery($id);
        if (!$entry || !$auth->canAccessCompany((int)$entry['company_id'])) return null;
        $lines = $this->pdo->prepare('SELECT ml.id,ml.sequence,ml.account_id,a.code AS account_code,a.name AS account_name,ml.contact_id,c.name AS contact_name,ml.label,ml.debit,ml.credit FROM accounting_move_lines ml JOIN accounting_accounts a ON a.id=ml.account_id LEFT JOIN contacts c ON c.id=ml.contact_id WHERE ml.move_id=:move ORDER BY ml.sequence,ml.id');
        $lines->execute(['move'=>$entry['move_id']]);
        $entry['lines'] = $lines->fetchAll();
        foreach ($entry['lines'] as &$line) { $line['id']=(int)$line['id'];$line['sequence']=(int)$line['sequence'];$line['account_id']=(int)$line['account_id'];if($line['contact_id']!==null)$line['contact_id']=(int)$line['contact_id']; }
        unset($line);
        $entry['id']=(int)$entry['id'];$entry['company_id']=(int)$entry['company_id'];$entry['move_id']=(int)$entry['move_id'];$entry['journal_id']=(int)$entry['journal_id'];$entry['has_reversal']=(bool)$entry['has_reversal'];if($entry['reversal_of_entry_id']!==null)$entry['reversal_of_entry_id']=(int)$entry['reversal_of_entry_id'];
        return $entry;
    }

    public function create(array $input, UserContext $user, Authorization $auth, int $actor, string $ip): array
    {
        $this->require($auth, 'accounting.write', 'create journal entries');
        $this->validateKeys($input);
        $companyId=$this->companyId($input['company_id']??$user->defaultCompanyId,$auth);
        $prepared=$this->prepareEntry($input,$companyId,$auth);
        $this->pdo->beginTransaction();
        try {
            $name='Draft/MISC/'.bin2hex(random_bytes(6));
            $this->pdo->prepare("INSERT INTO accounting_manual_journal_entries (company_id,name,reference,narration,created_by,updated_by) VALUES (:company,:name,:reference,:narration,:created_by,:updated_by)")->execute(['company'=>$companyId,'name'=>$name,'reference'=>$prepared['reference'],'narration'=>$prepared['narration'],'created_by'=>$actor,'updated_by'=>$actor]);
            $id=(int)$this->pdo->lastInsertId();
            $moveName='Draft/MISC/'.$id;
            $this->pdo->prepare('UPDATE accounting_manual_journal_entries SET name=:name WHERE id=:id')->execute(['name'=>$moveName,'id'=>$id]);
            $moveId=$this->insertMove($id,$companyId,$moveName,$prepared,$actor);
            $this->insertLines($moveId,$prepared['lines'],$prepared['currency'],$prepared['places']);
            $this->updateTotals($moveId,$prepared['debit'],$prepared['credit'],$prepared['places']);
            $this->audit($companyId,$actor,$id,'accounting.manual_journal_entry','manual_journal_entry_created',null,['name'=>$moveName,'journal_id'=>$prepared['journal_id'],'accounting_date'=>$prepared['date'],'reference'=>$prepared['reference'],'lines'=>$this->lineSnapshot($moveId)],$ip);
            $this->pdo->commit();
        } catch(Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error; }
        return $this->find($id,$user,$auth) ?? throw new InvalidArgumentException('Journal entry could not be reloaded.');
    }

    public function update(int $id, array $input, UserContext $user, Authorization $auth, int $actor, string $ip): array
    {
        $this->require($auth, 'accounting.write', 'edit draft journal entries');
        $this->validateKeys($input);
        $entry=$this->entryQuery($id);
        if(!$entry||!$auth->canAccessCompany((int)$entry['company_id']))throw new InvalidArgumentException('Journal entry was not found or is outside your allowed companies.');
        $companyId=(int)$entry['company_id'];
        if(isset($input['company_id'])&&(int)$input['company_id']!==$companyId)throw new InvalidArgumentException('A journal entry cannot be moved to another company.');
        $this->pdo->beginTransaction();
        try {
            $lock=$this->entryQuery($id,true);if(!$lock||$lock['state']!=='draft')throw new InvalidArgumentException('Only draft journal entries can be edited.');
            $prepared=$this->prepareEntry($input,$companyId,$auth);
            $before=['journal_id'=>(int)$lock['journal_id'],'accounting_date'=>$lock['accounting_date'],'reference'=>$lock['reference'],'narration'=>$lock['narration'],'lines'=>$this->lineSnapshot((int)$lock['move_id'])];
            $this->pdo->prepare('UPDATE accounting_manual_journal_entries SET reference=:reference,narration=:narration,updated_by=:actor WHERE id=:id')->execute(['reference'=>$prepared['reference'],'narration'=>$prepared['narration'],'actor'=>$actor,'id'=>$id]);
            $this->pdo->prepare("UPDATE accounting_moves SET journal_id=:journal,reference=:reference,accounting_date=:date WHERE id=:move AND state='draft'")->execute(['journal'=>$prepared['journal_id'],'reference'=>$prepared['reference'],'date'=>$prepared['date'],'move'=>$lock['move_id']]);
            $this->pdo->prepare('DELETE FROM accounting_move_lines WHERE move_id=:move')->execute(['move'=>$lock['move_id']]);
            $this->insertLines((int)$lock['move_id'],$prepared['lines'],$prepared['currency'],$prepared['places']);
            $this->updateTotals((int)$lock['move_id'],$prepared['debit'],$prepared['credit'],$prepared['places']);
            $this->audit($companyId,$actor,$id,'accounting.manual_journal_entry','manual_journal_entry_updated',$before,['journal_id'=>$prepared['journal_id'],'accounting_date'=>$prepared['date'],'reference'=>$prepared['reference'],'narration'=>$prepared['narration'],'lines'=>$this->lineSnapshot((int)$lock['move_id'])],$ip);
            $this->pdo->commit();
        } catch(Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error; }
        return $this->find($id,$user,$auth) ?? throw new InvalidArgumentException('Journal entry could not be reloaded.');
    }

    public function post(int $id, UserContext $user, Authorization $auth, int $actor, string $ip): array
    {
        $this->require($auth, 'accounting.post', 'post journal entries');
        $entry=$this->entryQuery($id);
        if(!$entry||!$auth->canAccessCompany((int)$entry['company_id']))throw new InvalidArgumentException('Journal entry was not found or is outside your allowed companies.');
        $this->pdo->beginTransaction();
        try {
            $entry=$this->entryQuery($id,true);if(!$entry||$entry['state']!=='draft')throw new InvalidArgumentException('Only draft journal entries can be posted.');
            AccountingDateGuard::lockAndAssertOpen($this->pdo,(int)$entry['company_id'],(string)$entry['accounting_date']);
            $this->assertPostable($entry);
            $name=$this->nextName((int)$entry['company_id'],(int)substr($entry['accounting_date'],0,4));
        $this->pdo->prepare("UPDATE accounting_moves SET name=:name,state='posted',posted_by=:actor,posted_at=UTC_TIMESTAMP() WHERE id=:move AND state='draft'")->execute(['name'=>$name,'actor'=>$actor,'move'=>$entry['move_id']]);
            $this->pdo->prepare("UPDATE accounting_manual_journal_entries SET name=:name,updated_by=:actor WHERE id=:id")->execute(['name'=>$name,'actor'=>$actor,'id'=>$id]);
            $this->audit((int)$entry['company_id'],$actor,$id,'accounting.manual_journal_entry','manual_journal_entry_posted',['state'=>'draft','name'=>$entry['name'],'debit'=>$entry['total_debit'],'credit'=>$entry['total_credit']],['state'=>'posted','name'=>$name,'accounting_move_id'=>(int)$entry['move_id'],'debit'=>$entry['total_debit'],'credit'=>$entry['total_credit'],'lines'=>$this->lineSnapshot((int)$entry['move_id'])],$ip);
            $this->pdo->commit();
        } catch(Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error; }
        return $this->find($id,$user,$auth) ?? throw new InvalidArgumentException('Posted journal entry could not be reloaded.');
    }

    public function reverse(int $id, array $input, UserContext $user, Authorization $auth, int $actor, string $ip): array
    {
        $this->require($auth, 'accounting.post', 'reverse posted journal entries');
        $unknown=array_diff(array_keys($input),['reversal_date','reference']);if($unknown)throw new InvalidArgumentException('Unsupported reversal fields: '.implode(', ',$unknown));
        $date=$this->date($input['reversal_date']??null,'Reversal date');
        $original=$this->entryQuery($id);
        if(!$original||!$auth->canAccessCompany((int)$original['company_id']))throw new InvalidArgumentException('Journal entry was not found or is outside your allowed companies.');
        $this->pdo->beginTransaction();
        try {
            $original=$this->entryQuery($id,true);
            if(!$original||$original['state']!=='posted'||$original['reversal_of_entry_id']!==null)throw new InvalidArgumentException('Only a posted original journal entry can be reversed.');
            AccountingDateGuard::lockAndAssertOpen($this->pdo,(int)$original['company_id'],$date);
            $existing=$this->pdo->prepare('SELECT id FROM accounting_manual_journal_entries WHERE reversal_of_entry_id=:id');$existing->execute(['id'=>$id]);if($existing->fetchColumn())throw new InvalidArgumentException('This journal entry already has a posted reversal.');
            $company=$this->company((int)$original['company_id']);
            $reference=trim((string)($input['reference']??('Reversal of '.$original['name'])));if(mb_strlen($reference)>120)throw new InvalidArgumentException('Reversal reference must be 120 characters or fewer.');
            $source=$this->pdo->prepare('SELECT account_id,contact_id,label,debit,credit FROM accounting_move_lines WHERE move_id=:move ORDER BY sequence,id FOR UPDATE');$source->execute(['move'=>$original['move_id']]);$sourceLines=$source->fetchAll();if(count($sourceLines)<2)throw new InvalidArgumentException('The original entry has no reversible journal lines.');
            $lines=[];$debit='0';$credit='0';
            foreach($sourceLines as $line){$d=$this->minor((string)$line['credit'],(int)$company['decimal_places']);$c=$this->minor((string)$line['debit'],(int)$company['decimal_places']);$debit=$this->add($debit,$d);$credit=$this->add($credit,$c);$lines[]=['account_id'=>(int)$line['account_id'],'contact_id'=>$line['contact_id']===null?null:(int)$line['contact_id'],'label'=>(string)$line['label'],'debit'=>$d,'credit'=>$c];}
            if($debit!==$credit||$debit==='0')throw new InvalidArgumentException('The original entry is not balanced and cannot be reversed.');
            $draft='Draft/REV/'.bin2hex(random_bytes(6));
            $this->pdo->prepare('INSERT INTO accounting_manual_journal_entries (company_id,name,reversal_of_entry_id,reference,narration,created_by,updated_by) VALUES (:company,:name,:original,:reference,:narration,:created_by,:updated_by)')->execute(['company'=>$original['company_id'],'name'=>$draft,'original'=>$id,'reference'=>$reference,'narration'=>'Reversal of '.$original['name'],'created_by'=>$actor,'updated_by'=>$actor]);
            $reversalId=(int)$this->pdo->lastInsertId();
            $name=$this->nextName((int)$original['company_id'],(int)substr($date,0,4));
            $prepared=['journal_id'=>(int)$original['journal_id'],'date'=>$date,'reference'=>$reference,'currency'=>$company['currency_code'],'debit'=>$debit,'credit'=>$credit];
            $this->pdo->prepare('UPDATE accounting_manual_journal_entries SET name=:name WHERE id=:id')->execute(['name'=>$name,'id'=>$reversalId]);
            $moveId=$this->insertMove($reversalId,(int)$original['company_id'],$name,$prepared,$actor,true);
            $this->insertLines($moveId,$lines,$company['currency_code'],(int)$company['decimal_places']);
            $this->pdo->prepare("UPDATE accounting_moves SET state='posted',posted_by=:actor,posted_at=UTC_TIMESTAMP() WHERE id=:move")->execute(['actor'=>$actor,'move'=>$moveId]);
            $this->audit((int)$original['company_id'],$actor,$reversalId,'accounting.manual_journal_entry','manual_journal_entry_reversed',null,['reversal_of_entry_id'=>$id,'original_name'=>$original['name'],'name'=>$name,'accounting_date'=>$date,'debit'=>$this->decimal($debit,(int)$company['decimal_places']),'credit'=>$this->decimal($credit,(int)$company['decimal_places']),'lines'=>$this->lineSnapshot($moveId)],$ip);
            $this->audit((int)$original['company_id'],$actor,$id,'accounting.manual_journal_entry','manual_journal_entry_reversal_created',['state'=>'posted'],['reversal_id'=>$reversalId,'reversal_name'=>$name],$ip);
            $this->pdo->commit();
        } catch(Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error; }
        return $this->find($reversalId,$user,$auth) ?? throw new InvalidArgumentException('Reversal entry could not be reloaded.');
    }

    public function discard(int $id, UserContext $user, Authorization $auth, int $actor, string $ip): void
    {
        $this->require($auth, 'accounting.write', 'discard draft journal entries');
        $entry=$this->entryQuery($id);
        if(!$entry||!$auth->canAccessCompany((int)$entry['company_id']))throw new InvalidArgumentException('Journal entry was not found or is outside your allowed companies.');
        $this->pdo->beginTransaction();
        try {
            $entry=$this->entryQuery($id,true);if(!$entry||$entry['state']!=='draft')throw new InvalidArgumentException('Only draft journal entries can be discarded.');
            $this->audit((int)$entry['company_id'],$actor,$id,'accounting.manual_journal_entry','manual_journal_entry_discarded',['state'=>'draft','name'=>$entry['name'],'reference'=>$entry['reference'],'lines'=>$this->lineSnapshot((int)$entry['move_id'])],['discarded'=>true],$ip);
            $this->pdo->prepare('DELETE FROM accounting_move_lines WHERE move_id=:move')->execute(['move'=>$entry['move_id']]);
            $this->pdo->prepare('DELETE FROM accounting_manual_journal_entries WHERE id=:id')->execute(['id'=>$id]);
            $this->pdo->prepare('DELETE FROM accounting_moves WHERE id=:move AND state=\'draft\'')->execute(['move'=>$entry['move_id']]);
            $this->pdo->commit();
        } catch(Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error; }
    }

    private function prepareEntry(array $input,int $companyId,Authorization $auth):array
    {
        $company=$this->company($companyId);$places=(int)$company['decimal_places'];if($places<0||$places>4)throw new InvalidArgumentException('Company currency precision is unsupported.');
        $date=$this->date($input['accounting_date']??null,'Accounting date');
        $journalId=$this->positiveId($input['journal_id']??null,'General Journal');
        $journal=$this->pdo->prepare("SELECT id FROM accounting_journals WHERE id=:id AND company_id=:company AND active=1 AND journal_type='general'");$journal->execute(['id'=>$journalId,'company'=>$companyId]);if(!$journal->fetchColumn())throw new InvalidArgumentException('Select an active General Journal for the selected company.');
        $reference=trim((string)($input['reference']??''));if(mb_strlen($reference)>120)throw new InvalidArgumentException('Reference must be 120 characters or fewer.');
        $narration=trim((string)($input['narration']??''));if(mb_strlen($narration)>5000)throw new InvalidArgumentException('Narration must be 5,000 characters or fewer.');
        $rawLines=$input['lines']??null;if(!is_array($rawLines)||!array_is_list($rawLines)||count($rawLines)<2||count($rawLines)>500)throw new InvalidArgumentException('A journal entry must contain between 2 and 500 lines.');
        $lines=[];$debit='0';$credit='0';
        foreach($rawLines as $index=>$line){
            if(!is_array($line))throw new InvalidArgumentException('Journal line '.($index+1).' is invalid.');
            $unknown=array_diff(array_keys($line),['account_id','contact_id','label','debit','credit']);if($unknown)throw new InvalidArgumentException('Unsupported fields in journal line '.($index+1).'.');
            $accountId=$this->positiveId($line['account_id']??null,'Line '.($index+1).' account');
            $account=$this->pdo->prepare('SELECT name,account_type FROM accounting_accounts WHERE id=:id AND company_id=:company AND active=1');$account->execute(['id'=>$accountId,'company'=>$companyId]);$accountRow=$account->fetch();if(!$accountRow)throw new InvalidArgumentException('Line '.($index+1).' account must be active and belong to the selected company.');
            $contactId=null;if(($line['contact_id']??'')!==''){$contactId=$this->positiveId($line['contact_id'],'Line '.($index+1).' Contact');$contact=$this->pdo->prepare('SELECT id FROM contacts WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company)');$contact->execute(['id'=>$contactId,'company'=>$companyId]);if(!$contact->fetchColumn())throw new InvalidArgumentException('Line '.($index+1).' Contact must be active and shared with or assigned to the selected company.');if(!$auth->allows('contacts.read'))throw new AccessDeniedException('You do not have permission to link Contacts to journal entries.');}
            if(in_array($accountRow['account_type'],['asset_receivable','liability_payable'],true)&&$contactId===null)throw new InvalidArgumentException('Line '.($index+1).' needs a Contact for a Receivable or Payable account.');
            $label=trim((string)($line['label']??''));if($label==='')$label=(string)$accountRow['name'];if(mb_strlen($label)>190)throw new InvalidArgumentException('Line '.($index+1).' label must be 190 characters or fewer.');
            $d=$this->minor((string)($line['debit']??'0'),$places,'Line '.($index+1).' debit');$c=$this->minor((string)($line['credit']??'0'),$places,'Line '.($index+1).' credit');
            if(($d==='0')===($c==='0'))throw new InvalidArgumentException('Each journal line must have a positive amount on exactly one side.');
            $debit=$this->add($debit,$d);$credit=$this->add($credit,$c);$lines[]=['account_id'=>$accountId,'contact_id'=>$contactId,'label'=>$label,'debit'=>$d,'credit'=>$c];
        }
        return ['journal_id'=>$journalId,'date'=>$date,'reference'=>$reference,'narration'=>$narration,'currency'=>$company['currency_code'],'places'=>$places,'debit'=>$debit,'credit'=>$credit,'lines'=>$lines];
    }

    private function insertMove(int $entryId,int $companyId,string $name,array $data,int $actor,bool $postedNow=false):int
    {
        $this->pdo->prepare("INSERT INTO accounting_moves (company_id,name,journal_id,move_type,reference_model,reference_id,reference,accounting_date,currency_code,state,total_debit,total_credit,created_by,posted_by,posted_at) VALUES (:company,:name,:journal,'general','manual_journal_entry',:entry,:reference,:date,:currency,:state,:debit,:credit,:actor,:poster,:posted_at)")->execute(['company'=>$companyId,'name'=>$name,'journal'=>$data['journal_id'],'entry'=>$entryId,'reference'=>$data['reference'],'date'=>$data['date'],'currency'=>$data['currency'],'state'=>$postedNow?'posted':'draft','debit'=>$this->decimal($data['debit'],$data['places']??(int)$this->company($companyId)['decimal_places']),'credit'=>$this->decimal($data['credit'],$data['places']??(int)$this->company($companyId)['decimal_places']),'actor'=>$actor,'poster'=>$postedNow?$actor:null,'posted_at'=>$postedNow?gmdate('Y-m-d H:i:s'):null]);
        $moveId=(int)$this->pdo->lastInsertId();$this->pdo->prepare('UPDATE accounting_manual_journal_entries SET accounting_move_id=:move WHERE id=:id')->execute(['move'=>$moveId,'id'=>$entryId]);return$moveId;
    }

    private function insertLines(int $moveId,array $lines,string $currency,int $places):void
    {
        $insert=$this->pdo->prepare('INSERT INTO accounting_move_lines (move_id,sequence,account_id,contact_id,label,debit,credit,currency_code,amount_currency) VALUES (:move,:sequence,:account,:contact,:label,:debit,:credit,:currency,:amount)');
        foreach($lines as $index=>$line){$debit=$this->decimal($line['debit'],$places);$credit=$this->decimal($line['credit'],$places);$insert->execute(['move'=>$moveId,'sequence'=>($index+1)*10,'account'=>$line['account_id'],'contact'=>$line['contact_id'],'label'=>$line['label'],'debit'=>$debit,'credit'=>$credit,'currency'=>$currency,'amount'=>$line['debit']!=='0'?$debit:'-'.$credit]);}
    }

    private function assertPostable(array $entry):void
    {
        $company=$this->company((int)$entry['company_id']);$places=(int)$company['decimal_places'];
        $journal=$this->pdo->prepare("SELECT id FROM accounting_journals WHERE id=:id AND company_id=:company AND active=1 AND journal_type='general' FOR UPDATE");$journal->execute(['id'=>$entry['journal_id'],'company'=>$entry['company_id']]);if(!$journal->fetchColumn())throw new InvalidArgumentException('The General Journal must still be active in the entry company.');
        $lines=$this->pdo->prepare('SELECT ml.debit,ml.credit,ml.contact_id,a.id AS account_id,a.account_type,c.id AS valid_contact_id FROM accounting_move_lines ml JOIN accounting_accounts a ON a.id=ml.account_id AND a.company_id=:company AND a.active=1 LEFT JOIN contacts c ON c.id=ml.contact_id AND c.active=1 AND (c.company_id IS NULL OR c.company_id=:contact_company) WHERE ml.move_id=:move ORDER BY ml.sequence,ml.id FOR UPDATE');$lines->execute(['company'=>$entry['company_id'],'contact_company'=>$entry['company_id'],'move'=>$entry['move_id']]);$rows=$lines->fetchAll();if(count($rows)<2)throw new InvalidArgumentException('At least two active-account journal lines are required.');
        $debit='0';$credit='0';foreach($rows as $line){if($line['contact_id']!==null&&$line['valid_contact_id']===null)throw new InvalidArgumentException('A linked Contact is no longer active or available to the entry company.');if(in_array($line['account_type'],['asset_receivable','liability_payable'],true)&&$line['contact_id']===null)throw new InvalidArgumentException('A Receivable or Payable line needs a Contact before posting.');$debit=$this->add($debit,$this->minor((string)$line['debit'],$places));$credit=$this->add($credit,$this->minor((string)$line['credit'],$places));}
        if($debit==='0'||$debit!==$credit)throw new InvalidArgumentException('Journal entry is not balanced. Debit and credit totals must be equal and greater than zero.');
        $this->updateTotals((int)$entry['move_id'],$debit,$credit,$places);
    }

    private function updateTotals(int $moveId,string $debit,string $credit,int $places):void{$this->pdo->prepare('UPDATE accounting_moves SET total_debit=:debit,total_credit=:credit WHERE id=:move AND state=\'draft\'')->execute(['debit'=>$this->decimal($debit,$places),'credit'=>$this->decimal($credit,$places),'move'=>$moveId]);}

    private function nextName(int $company,int $year):string
    {
        $this->pdo->prepare('INSERT INTO accounting_manual_entry_sequences (company_id,sequence_year,last_number) VALUES (:company,:year,0) ON DUPLICATE KEY UPDATE last_number=last_number')->execute(['company'=>$company,'year'=>$year]);
        $query=$this->pdo->prepare('SELECT last_number FROM accounting_manual_entry_sequences WHERE company_id=:company AND sequence_year=:year FOR UPDATE');$query->execute(['company'=>$company,'year'=>$year]);$next=(int)$query->fetchColumn()+1;
        $this->pdo->prepare('UPDATE accounting_manual_entry_sequences SET last_number=:number WHERE company_id=:company AND sequence_year=:year')->execute(['number'=>$next,'company'=>$company,'year'=>$year]);return sprintf('MISC/%04d/%05d',$year,$next);
    }

    private function validateKeys(array $input):void{$unknown=array_diff(array_keys($input),['company_id','journal_id','accounting_date','reference','narration','lines']);if($unknown)throw new InvalidArgumentException('Unsupported journal entry fields: '.implode(', ',$unknown));}
    private function entryQuery(int $id,bool $lock=false):?array{$sql='SELECT e.id,e.company_id,e.name,e.reference,e.narration,e.reversal_of_entry_id,EXISTS(SELECT 1 FROM accounting_manual_journal_entries rev WHERE rev.reversal_of_entry_id=e.id) AS has_reversal,e.accounting_move_id AS move_id,m.name AS move_name,m.journal_id,j.code AS journal_code,j.name AS journal_name,m.accounting_date,m.currency_code,m.state,m.total_debit,m.total_credit,m.posted_by,m.posted_at FROM accounting_manual_journal_entries e JOIN accounting_moves m ON m.id=e.accounting_move_id JOIN accounting_journals j ON j.id=m.journal_id WHERE e.id=:id'.($lock?' FOR UPDATE':'');$query=$this->pdo->prepare($sql);$query->execute(['id'=>$id]);return$query->fetch()?:null;}
    private function companyId(mixed $raw,Authorization $auth):int{$id=$this->positiveId($raw,'Company');if(!$auth->canAccessCompany($id))throw new InvalidArgumentException('Company is outside your allowed company scope.');$this->company($id);return$id;}
    private function company(int $id):array{$query=$this->pdo->prepare('SELECT c.id,c.name,c.currency_code,cur.decimal_places FROM companies c JOIN currencies cur ON cur.currency_code=c.currency_code WHERE c.id=:id AND c.active=1');$query->execute(['id'=>$id]);$row=$query->fetch();if(!$row)throw new InvalidArgumentException('Company was not found or is archived.');$row['id']=(int)$row['id'];$row['decimal_places']=(int)$row['decimal_places'];return$row;}
    private function positiveId(mixed $value,string $label):int{$id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive integer.');return$id;}
    private function date(mixed $value,string $label):string{$raw=is_string($value)?$value:'';$date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);if(!$date||$date->format('Y-m-d')!==$raw)throw new InvalidArgumentException($label.' must be a valid YYYY-MM-DD date.');return$raw;}
    private function minor(string $value,int $places,string $label='Amount'):string{if(!preg_match('/^(\d{1,14})(?:\.(\d{1,6}))?$/D',$value,$m))throw new InvalidArgumentException($label.' must be a non-negative decimal with at most 14 whole digits.');$whole=ltrim($m[1],'0')?:'0';$fraction=rtrim($m[2]??'','0');if(strlen($fraction)>$places)throw new InvalidArgumentException($label.' has more decimal places than the company currency allows.');$digits=ltrim($whole.str_pad($fraction,$places,'0'),'0');return$digits===''?'0':$digits;}
    private function decimal(string $minor,int $places):string{if($places===0)return$minor;$digits=str_pad($minor,$places+1,'0',STR_PAD_LEFT);return substr($digits,0,-$places).'.'.substr($digits,-$places);}
    private function add(string $a,string $b):string{$i=strlen($a)-1;$j=strlen($b)-1;$carry=0;$out='';while($i>=0||$j>=0||$carry){$sum=($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0)+$carry;$out=($sum%10).$out;$carry=intdiv($sum,10);}return ltrim($out,'0')?:'0';}
    private function require(Authorization $auth,string $permission,string $action):void{if(!$auth->allows($permission))throw new AccessDeniedException('You do not have permission to '.$action.'.');}
    private function castEntries(array $rows):array{foreach($rows as &$row){foreach(['id','company_id','move_id','journal_id'] as $key)$row[$key]=(int)$row[$key];if($row['reversal_of_entry_id']!==null)$row['reversal_of_entry_id']=(int)$row['reversal_of_entry_id'];if(isset($row['has_reversal']))$row['has_reversal']=(bool)$row['has_reversal'];if($row['posted_by']!==null)$row['posted_by']=(int)$row['posted_by'];}unset($row);return$rows;}
    private function lineSnapshot(int $moveId):array{$query=$this->pdo->prepare('SELECT account_id,contact_id,label,debit,credit FROM accounting_move_lines WHERE move_id=:move ORDER BY sequence,id');$query->execute(['move'=>$moveId]);$lines=$query->fetchAll();foreach($lines as &$line){$line['account_id']=(int)$line['account_id'];if($line['contact_id']!==null)$line['contact_id']=(int)$line['contact_id'];$line['debit']=(string)$line['debit'];$line['credit']=(string)$line['credit'];}unset($line);return$lines;}
    private function audit(int $company,int $actor,int $record,string $model,string $event,?array $old,array $new,string $remote):void{$ip=filter_var($remote,FILTER_VALIDATE_IP)?inet_pton($remote):null;$q=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');$q->bindValue(':company',$company,PDO::PARAM_INT);$q->bindValue(':user',$actor,PDO::PARAM_INT);$q->bindValue(':event',$event);$q->bindValue(':model',$model);$q->bindValue(':record',$record,PDO::PARAM_INT);$q->bindValue(':old_values',$old===null?null:json_encode($old,JSON_THROW_ON_ERROR),$old===null?PDO::PARAM_NULL:PDO::PARAM_STR);$q->bindValue(':new_values',json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$q->bindValue(':ip',$ip,$ip===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$q->execute();}
}
